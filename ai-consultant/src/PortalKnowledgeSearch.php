<?php
declare(strict_types=1);

/** Live-RAG по каталогу и редактируемым проверенным статьям. */
class PortalKnowledgeSearch
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function search(string $query, int $limit = 8): array
    {
        $q = mb_strtolower(trim($query));
        $facts = [];
        $type = $this->detectType($q);
        $keywords = $this->keywords($q);

        $loaders = $type ? [$type] : ['webinars', 'courses', 'competitions', 'olympiads'];
        foreach ($loaders as $loader) {
            try { $facts = array_merge($facts, $this->{'search' . ucfirst($loader)}($q, $keywords, max(2, (int)ceil($limit / count($loaders))))); }
            catch (Throwable $e) { ai_log('MESSENGER_KNOWLEDGE', 'Catalog search failed', ['type' => $loader, 'error' => $e->getMessage()]); }
        }
        try { $facts = array_merge($facts, $this->searchArticles($q, $keywords, 4)); }
        catch (Throwable $e) { ai_log('MESSENGER_KNOWLEDGE', 'Article search failed', ['error' => $e->getMessage()]); }
        return array_slice($facts, 0, $limit + 4);
    }

    private function detectType(string $q): ?string
    {
        foreach (['webinars'=>'/(вебинар|видеолекц)/u','courses'=>'/(курс|кпк|переподготов|квалификац|обучени)/u','competitions'=>'/конкурс/u','olympiads'=>'/олимпиад/u'] as $type=>$re) {
            if (preg_match($re, $q)) return $type;
        }
        return null;
    }

    private function keywords(string $q): array
    {
        $words = preg_split('/[^\p{L}\p{N}-]+/u', $q) ?: [];
        $stop = ['как','когда','где','сколько','можно','нужно','есть','ли','что','какой','какая','какие','подскажите','расскажите','хочу','узнать','курс','курсы','обучение','вебинар','вебинары','конкурс','олимпиада','для','про','мой','мне','ваш','ваша'];
        $filtered=array_filter($words, fn($w) => mb_strlen($w) >= 4 && !in_array($w, $stop, true));
        return array_slice(array_values(array_unique(array_map(fn($w)=>$this->stem($w),$filtered))),0,5);
    }

    private function stem(string $word): string
    {
        if (mb_strlen($word) < 6) return $word;
        $stem=preg_replace('/(иями|ями|ами|ировать|овать|ение|ений|ить|ать|ять|ого|ему|ому|ами|ями|ов|ев|ам|ям|ах|ях|ом|ем|ую|юю|ая|яя|ое|ее|ый|ий|ой|ы|и|а|я|у|ю|е)$/u','',$word);
        return is_string($stem)&&mb_strlen($stem)>=4?$stem:$word;
    }

    private function likeWhere(array $keywords, array $columns, array &$params): string
    {
        if (!$keywords) return '1=1';
        $groups = [];
        foreach ($keywords as $kw) {
            $ors = [];
            foreach ($columns as $col) { $ors[] = "LOWER($col) LIKE ?"; $params[] = '%' . $kw . '%'; }
            $groups[] = '(' . implode(' OR ', $ors) . ')';
        }
        return '(' . implode(' OR ', $groups) . ')';
    }

    private function searchCourses(string $q, array $keywords, int $limit): array
    {
        $params=[]; $where=$this->likeWhere($keywords,['title','description','target_audience_text','course_group'], $params);
        $stmt=$this->pdo->prepare("SELECT id,title,slug,description,target_audience_text,course_group,hours,program_type,learning_format,price,modules_json,outcomes_json,federal_registry_info FROM courses WHERE is_active=1 AND $where ORDER BY display_order,id DESC LIMIT " . (int)$limit);
        $stmt->execute($params);
        return array_map(fn($r)=>$this->fact('course',$r['id'],$r['title'],'/kursy/'.$r['slug'].'/',[
            'тип программы'=>$r['program_type']==='pp'?'профессиональная переподготовка':'повышение квалификации','часы'=>(int)$r['hours'],'формат'=>$r['learning_format'],'цена'=>(float)$r['price'],'для кого'=>$r['target_audience_text'],'описание'=>$r['description'],'модули'=>$this->jsonValue($r['modules_json']),'результаты'=>$this->jsonValue($r['outcomes_json']),'ФРДО'=>$r['federal_registry_info']]),$stmt->fetchAll());
    }

    private function searchWebinars(string $q, array $keywords, int $limit): array
    {
        $temporal=(bool)preg_match('/(ближайш|следующ|когда|расписани|предстоящ)/u',$q);
        $params=[]; $where=$temporal?'1=1':$this->likeWhere($keywords,['w.title','w.description','w.short_description'], $params);
        $order=$temporal?"CASE WHEN w.status IN ('scheduled','live') AND w.scheduled_at>=NOW() THEN 0 ELSE 1 END, w.scheduled_at ASC":"w.id DESC";
        $stmt=$this->pdo->prepare("SELECT w.id,w.title,w.slug,w.description,w.short_description,w.scheduled_at,w.duration_minutes,w.timezone,w.status,w.is_free,w.certificate_price,w.certificate_hours,s.full_name speaker FROM webinars w LEFT JOIN speakers s ON s.id=w.speaker_id WHERE w.is_active=1 AND w.status IN ('scheduled','live','completed','videolecture') AND $where ORDER BY $order LIMIT ".(int)$limit);
        $stmt->execute($params);
        return array_map(fn($r)=>$this->fact('webinar',$r['id'],$r['title'],'/vebinar/'.$r['slug'].'/',[
            'статус'=>$r['status'],'дата и время'=>$r['scheduled_at'],'часовой пояс'=>$r['timezone'],'длительность, мин'=>(int)$r['duration_minutes'],'участие бесплатное'=>(bool)$r['is_free'],'сертификат, ₽'=>(float)$r['certificate_price'],'сертификат, ак. ч.'=>(int)$r['certificate_hours'],'спикер'=>$r['speaker'],'описание'=>$r['short_description']?:strip_tags((string)$r['description'])]),$stmt->fetchAll());
    }

    private function searchCompetitions(string $q, array $keywords, int $limit): array
    {
        $params=[]; $where=$this->likeWhere($keywords,['title','description','target_participants','category'], $params);
        $stmt=$this->pdo->prepare("SELECT id,title,slug,description,target_participants,award_structure,academic_year,category,nomination_options,price FROM competitions WHERE is_active=1 AND $where ORDER BY display_order,id DESC LIMIT ".(int)$limit); $stmt->execute($params);
        return array_map(fn($r)=>$this->fact('competition',$r['id'],$r['title'],'/konkursy/'.$r['slug'].'/',['описание'=>$r['description'],'участники'=>$r['target_participants'],'награды'=>$r['award_structure'],'учебный год'=>$r['academic_year'],'категория'=>$r['category'],'номинации'=>$this->jsonValue($r['nomination_options']),'цена'=>(float)$r['price']]),$stmt->fetchAll());
    }

    private function searchOlympiads(string $q, array $keywords, int $limit): array
    {
        $params=[]; $where=$this->likeWhere($keywords,['title','description','subject','grade'], $params);
        $stmt=$this->pdo->prepare("SELECT id,title,slug,description,target_audience,subject,grade,diploma_price,academic_year FROM olympiads WHERE is_active=1 AND $where ORDER BY display_order,id DESC LIMIT ".(int)$limit); $stmt->execute($params);
        return array_map(fn($r)=>$this->fact('olympiad',$r['id'],$r['title'],'/olimpiady/'.$r['slug'].'/',['описание'=>$r['description'],'аудитория'=>$r['target_audience'],'предмет'=>$r['subject'],'класс'=>$r['grade'],'диплом, ₽'=>(float)$r['diploma_price'],'учебный год'=>$r['academic_year']]),$stmt->fetchAll());
    }

    private function searchArticles(string $q, array $keywords, int $limit): array
    {
        if (!$keywords) return [];
        $params=[]; $where=$this->likeWhere($keywords,['title','content','topic'],$params);
        $stmt=$this->pdo->prepare("SELECT id,slug,topic,title,content,source_url FROM ai_knowledge_articles WHERE is_active=1 AND $where ORDER BY updated_at DESC LIMIT ".(int)$limit); $stmt->execute($params);
        return array_map(fn($r)=>$this->fact('knowledge',$r['id'],$r['title'],$r['source_url']?:null,['тема'=>$r['topic'],'текст'=>$r['content']]),$stmt->fetchAll());
    }

    private function fact(string $type, $id, string $title, ?string $url, array $data): array
    {
        return ['source_id'=>$type.':'.$id,'type'=>$type,'title'=>$title,'url'=>$url ? rtrim((defined('PUBLIC_SITE_URL')?PUBLIC_SITE_URL:AI_SITE_URL),'/').$url : null,'data'=>$this->clean($data)];
    }
    private function clean(array $data): array { foreach($data as $k=>$v){ if(is_string($v)) $data[$k]=mb_substr(trim(preg_replace('/\s+/u',' ',strip_tags($v))??''),0,2500); } return array_filter($data,fn($v)=>$v!==null&&$v!==''&&$v!==[]); }
    private function jsonValue($value) { if(!$value)return null; $d=json_decode((string)$value,true); $text=is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):(string)$value; return mb_substr((string)$text,0,4000); }
}
