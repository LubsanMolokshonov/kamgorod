<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }

/** Атомарный импорт каталога с проверкой исходников и обратимым журналом. */
class CatalogRelease {
    public function __construct(private PDO $pdo) {}

    private function canonicalJson(string $json): string {
        $normalize = static function ($value) use (&$normalize) {
            if (is_object($value)) {
                $fields = get_object_vars($value); ksort($fields, SORT_STRING);
                foreach ($fields as &$field) $field = $normalize($field);
                return (object)$fields;
            }
            if (is_array($value)) return array_map($normalize, $value);
            return $value;
        };
        return json_encode($normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }

    private function row(string $table, string $key, $value): ?array {
        $q = $this->pdo->prepare("SELECT * FROM `$table` WHERE `$key`=? FOR UPDATE");
        $q->execute([$value]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function matches(array $row, array $expected): bool {
        foreach ($expected as $key => $value) {
            if (in_array($key, ['participant_roles','education_stages','subject_slugs','content_json','modules_json'], true)
                && $value !== null && isset($row[$key])) {
                if ($this->canonicalJson($row[$key]) !== $this->canonicalJson($value)) return false;
                continue;
            }
            if ($value === null ? ($row[$key] ?? null) !== null : (string)($row[$key] ?? '') !== (string)$value) return false;
        }
        return true;
    }

    private function change(string $package, string $table, string $key, $id, array $values, bool $apply): bool {
        $old = $this->row($table, $key, $id);
        if ($old && $this->matches($old, $values)) return false;
        if (!$apply) return true;
        $before = $old ? array_intersect_key($old, $values) : null;
        $q = $this->pdo->prepare('INSERT INTO catalog_change_history(package_id,entity_type,entity_key,old_values,new_values) VALUES(?,?,?,?,?)');
        $q->execute([$package, $table, (string)$id, $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        if (!$old) {
            $cols = array_merge([$key], array_keys($values));
            $q = $this->pdo->prepare("INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
            $q->execute([$id, ...array_values($values)]);
        } else {
            $assignments = implode(',', array_map(static fn($k) => "`$k`=?", array_keys($values)));
            $q = $this->pdo->prepare("UPDATE `$table` SET $assignments WHERE `$key`=?");
            $q->execute([...array_values($values), $id]);
        }
        return true;
    }

    public function run(array $package, string $section, bool $apply): array {
        if (!preg_match('/^[a-z0-9-]{1,100}$/D', $package['package_id'] ?? '') || !in_array($section, ['courses','competitions'], true)) throw new InvalidArgumentException('Некорректный пакет или раздел');
        $result = ['changes' => 0, 'unchanged' => 0, 'deferred_categories' => []];
        $this->pdo->beginTransaction();
        try {
            if ($section === 'courses') {
                // Старые заявки должны быть застрахованы до любого переименования.
                $missing = $this->pdo->query("SELECT COUNT(*) FROM course_enrollments WHERE course_title_snapshot IS NULL OR course_title_snapshot=''")->fetchColumn();
                if ($missing) throw new RuntimeException('Сначала заполните снимки названий существующих заявок');
                foreach ($package['courses'] as $row) {
                    if (!is_string($row['title'] ?? null) || trim($row['title']) === '' || mb_strlen($row['title']) > 255
                        || array_diff(['title','slug','hours','program_type','modules_json','is_active'], array_keys($row['expected'] ?? []))) throw new RuntimeException('Неполные данные курса');
                    if (in_array((int)$row['id'], [39,72,79,119,130,133,149,155], true)) throw new RuntimeException('Спорный курс в основном пакете');
                    $current = $this->row('courses', 'id', $row['id']);
                    $expected = $row['expected'];
                    $already = $expected; $already['title'] = $row['title'];
                    if (!$current || (!$this->matches($current, $expected) && !$this->matches($current, $already))) throw new RuntimeException('Изменился исходник курса ' . $row['id']);
                    if ($row['path'] !== '/kursy/' . $current['slug'] . '/') throw new RuntimeException('Изменился URL курса');
                    $changed = $this->change($package['package_id'], 'courses', 'id', $row['id'], ['title' => $row['title']], $apply);
                    $result[$changed ? 'changes' : 'unchanged']++;
                }
            } else {
                foreach ($package['facts'] as $row) {
                    $source = $this->row('competitions', 'id', $row['id']);
                    if (!$source || !$this->matches($source, $row['expected'])) throw new RuntimeException('Изменился исходник конкурса ' . $row['id']);
                    $values = array_intersect_key($row['values'], array_flip(['participant_roles','education_stages','subject_slugs','work_type','applicant_role','content_json','evidence']));
                    foreach (['participant_roles','education_stages','subject_slugs'] as $key) {
                        if (!isset($values[$key]) || !is_array($values[$key])) throw new RuntimeException('Некорректная классификация');
                        foreach ($values[$key] as $value) if (!is_string($value) || !preg_match('/^[a-z0-9-]+$/D', $value)) throw new RuntimeException('Некорректный идентификатор классификации');
                        $values[$key] = json_encode($values[$key], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    }
                    if (!$row['values']['participant_roles'] || array_diff($row['values']['participant_roles'], ['pedagogi','doshkolnikam','shkolnikam','studentam-spo'])) throw new RuntimeException('Неизвестная роль участника');
                    if (($values['applicant_role'] ?? '') !== 'adult' || !is_array($values['content_json'] ?? null)
                        || (isset($values['content_json']['year_round']) && !is_bool($values['content_json']['year_round']))) throw new RuntimeException('Некорректные подтвержденные условия');
                    if (!in_array($values['work_type'], ['methodology','extracurricular','student_projects','creative'], true)) throw new RuntimeException('Неизвестный тип работы');
                    $values['content_json'] = json_encode((object)$values['content_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $existing = $this->row('competition_catalog_facts', 'competition_id', $row['id']);
                    $values['reviewed_at'] = $existing['reviewed_at'] ?? '2026-10-21 00:00:00';
                    if ($existing && !$this->matches($existing, $values)
                        && (!isset($row['expected_facts']) || !$this->matches($existing, $row['expected_facts']))) {
                        throw new RuntimeException('Конфликт ручной классификации конкурса ' . $row['id']);
                    }
                    $changed = $this->change($package['package_id'], 'competition_catalog_facts', 'competition_id', $row['id'], $values, $apply);
                    $result[$changed ? 'changes' : 'unchanged']++;
                }
            }
            foreach ($package['metadata'] as $row) {
                if (($section === 'courses') !== ($row['entity'] === 'courses')) continue;
                if (in_array($row['entity'], ['courses','competitions'], true)) {
                    $source = $this->row($row['entity'], 'id', $row['id']);
                    $prefix = $row['entity'] === 'courses' ? 'kursy' : 'konkursy';
                    if (!$source || !$source['is_active'] || $row['path'] !== '/' . $prefix . '/' . $source['slug'] . '/') throw new RuntimeException('Изменился источник метаданных ' . $row['path']);
                } elseif ($row['entity'] !== 'category') throw new RuntimeException('Неизвестный тип метаданных');
                $values = array_intersect_key($row['values'], array_flip(['seo_h1','meta_title','meta_description','intro_text','index_catalog']));
                if ($row['entity'] === 'category' && $row['path'] !== '/konkursy/') {
                    require_once __DIR__ . '/../includes/catalog-seo.php';
                    if (!isset(competitionRegistry()['categories'][$row['path']])) throw new RuntimeException('Категория отсутствует в утвержденном реестре');
                    $route = parseCatalogPath($row['path']);
                    // Особые посадочные также используют подтвержденный педагогический ассортимент.
                    $options = $route['options'] ?? ['ac'=>'pedagogi'];
                    if (!CatalogListing::usesCompetitionFacts() || !competitionClassificationReady($this->pdo, $options)
                        || (new CatalogListing($this->pdo, 'konkursy', $options))->count() === 0) {
                        $result['deferred_categories'][] = $row['path'];
                        continue;
                    }
                }
                if (!preg_match('~^/(?:[a-z0-9-]+/)+$~D', $row['path'])) throw new RuntimeException('Некорректный URL метаданных');
                $current = $this->row('seo_page_overrides', 'path', $row['path']) ?? [];
                foreach ($values as $field => $value) {
                    if (!$this->matches($current, [$field => $value]) && !$this->matches($current, [$field => $row['expected'][$field] ?? null])) throw new RuntimeException('Конфликт SEO-поля: ' . $row['path'] . ' ' . $field);
                }
                $changed = $this->change($package['package_id'], 'seo_page_overrides', 'path', $row['path'], $values, $apply);
                $result[$changed ? 'changes' : 'unchanged']++;
            }
            if ($apply) $this->pdo->commit(); else $this->pdo->rollBack();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function rollback(string $packageId, bool $apply): int {
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT * FROM catalog_change_history WHERE package_id=? AND reverted_at IS NULL ORDER BY id DESC FOR UPDATE');
            $q->execute([$packageId]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $virtual = [];
            foreach ($rows as $row) {
                $keys = ['courses'=>'id','seo_page_overrides'=>'path','competition_catalog_facts'=>'competition_id'];
                $table = $row['entity_type'];
                if (!isset($keys[$table])) throw new RuntimeException('Неизвестная таблица журнала');
                $key = $keys[$table];
                $virtualKey = $table . ':' . $row['entity_key'];
                $current = array_key_exists($virtualKey, $virtual) ? $virtual[$virtualKey] : ($this->row($table, $key, $row['entity_key']) ?? []);
                $new = json_decode($row['new_values'], true, 512, JSON_THROW_ON_ERROR);
                if (!$this->matches($current, $new)) throw new RuntimeException('Откат запрещен: запись изменена после импорта ' . $row['entity_key']);
                $old = $row['old_values'] === null ? null : json_decode($row['old_values'], true, 512, JSON_THROW_ON_ERROR);
                if ($old === null) {
                    // Новую SEO-запись не удаляем, если позднее в ней появились другие сведения.
                    if ($table === 'seo_page_overrides') {
                        foreach (['seo_h1','meta_title','meta_description','editorial_json','intro_text','index_catalog'] as $field) {
                            if (!array_key_exists($field, $new) && !empty($current[$field])) throw new RuntimeException('Новые редакционные сведения препятствуют удалению: ' . $field);
                        }
                    }
                    $virtual[$virtualKey] = [];
                    if (!$apply) continue;
                    $q = $this->pdo->prepare("DELETE FROM `$table` WHERE `$key`=?"); $q->execute([$row['entity_key']]);
                } else {
                    $virtual[$virtualKey] = array_replace($current, $old);
                    if (!$apply) continue;
                    $q = $this->pdo->prepare("UPDATE `$table` SET " . implode(',', array_map(static fn($k) => "`$k`=?", array_keys($old))) . " WHERE `$key`=?");
                    $q->execute([...array_values($old), $row['entity_key']]);
                }
                $q = $this->pdo->prepare('UPDATE catalog_change_history SET reverted_at=UTC_TIMESTAMP() WHERE id=?'); $q->execute([$row['id']]);
            }
            if ($apply) $this->pdo->commit(); else $this->pdo->rollBack();
            return count($rows);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
