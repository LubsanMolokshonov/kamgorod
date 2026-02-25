<?php
/**
 * Publication Class
 * Handles publication CRUD operations for the journal section
 */

class Publication {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Create a new publication
     * @param array $data Publication data
     * @return int Publication ID
     */
    public function create($data) {
        $insertData = [
            'user_id' => $data['user_id'],
            'title' => $data['title'],
            'annotation' => $data['annotation'] ?? '',
            'content' => $data['content'] ?? null,
            'file_path' => $data['file_path'] ?? null,
            'file_original_name' => $data['file_original_name'] ?? null,
            'file_size' => $data['file_size'] ?? null,
            'file_type' => $data['file_type'] ?? null,
            'publication_type_id' => $data['publication_type_id'] ?? null,
            'slug' => $data['slug'] ?? $this->generateSlug($data['title']),
            'status' => $data['status'] ?? 'published', // Auto-publish publications
            'certificate_status' => $data['certificate_status'] ?? 'none',
            'published_at' => date('Y-m-d H:i:s') // Set publish date
        ];

        $publicationId = $this->db->insert('publications', $insertData);

        // Attach tags if provided
        if (!empty($data['tag_ids']) && is_array($data['tag_ids'])) {
            $this->attachTags($publicationId, $data['tag_ids']);
        }

        // Update user publications count
        $this->updateUserPublicationsCount($data['user_id']);

        return $publicationId;
    }

    /**
     * Update publication
     * @param int $id Publication ID
     * @param array $data Update data
     * @return int Affected rows
     */
    public function update($id, $data) {
        $allowedFields = [
            'title', 'annotation', 'content', 'file_path', 'file_original_name',
            'file_size', 'file_type', 'publication_type_id', 'slug', 'status',
            'moderation_comment', 'moderation_type', 'moderated_at', 'gpt_confidence',
            'certificate_status', 'meta_title', 'meta_description'
        ];

        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        // Handle status change to published
        if (isset($data['status']) && $data['status'] === 'published') {
            $updateData['published_at'] = date('Y-m-d H:i:s');
        }

        $result = $this->db->update('publications', $updateData, 'id = ?', [$id]);

        // Update tags if provided
        if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
            $this->attachTags($id, $data['tag_ids']);
        }

        return $result;
    }

    /**
     * Delete publication
     * @param int $id Publication ID
     * @return int Affected rows
     */
    public function delete($id) {
        $publication = $this->getById($id);
        if ($publication) {
            // Delete file if exists
            if ($publication['file_path']) {
                $filePath = __DIR__ . '/../uploads/publications/' . $publication['file_path'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // Update user count
            $this->updateUserPublicationsCount($publication['user_id']);
        }

        return $this->db->delete('publications', 'id = ?', [$id]);
    }

    /**
     * Get publication by ID
     * @param int $id Publication ID
     * @return array|null Publication data
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT p.*, pt.name as type_name, pt.slug as type_slug,
                    u.full_name as author_name, u.organization as author_organization
             FROM publications p
             LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
             LEFT JOIN users u ON p.user_id = u.id
             WHERE p.id = ?",
            [$id]
        );
    }

    /**
     * Get publication by slug
     * @param string $slug Publication slug
     * @return array|null Publication data
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT p.*, pt.name as type_name, pt.slug as type_slug,
                    u.full_name as author_name, u.organization as author_organization
             FROM publications p
             LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
             LEFT JOIN users u ON p.user_id = u.id
             WHERE p.slug = ? AND p.status = 'published'",
            [$slug]
        );
    }

    /**
     * Get published publications with pagination
     * @param int $limit Limit
     * @param int $offset Offset
     * @param array $filters Optional filters
     * @return array Publications
     */
    public function getPublished($limit = 20, $offset = 0, $filters = []) {
        $sql = "SELECT p.*, pt.name as type_name, pt.slug as type_slug,
                       u.full_name as author_name, u.organization as author_organization
                FROM publications p
                LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
                LEFT JOIN users u ON p.user_id = u.id";

        $wheres = ["p.status = 'published'"];
        $params = [];

        // Filter by tag
        if (!empty($filters['tag_id'])) {
            $sql .= " JOIN publication_tag_relations ptr ON p.id = ptr.publication_id";
            $wheres[] = "ptr.tag_id = ?";
            $params[] = $filters['tag_id'];
        }

        // Filter by type
        if (!empty($filters['type_id'])) {
            $wheres[] = "p.publication_type_id = ?";
            $params[] = $filters['type_id'];
        }

        // Filter by user
        if (!empty($filters['user_id'])) {
            $wheres[] = "p.user_id = ?";
            $params[] = $filters['user_id'];
        }

        $sql .= " WHERE " . implode(" AND ", $wheres);

        // Sorting
        $sortBy = $filters['sort'] ?? 'date';
        if ($sortBy === 'popular') {
            $sql .= " ORDER BY p.views_count DESC, p.published_at DESC";
        } else {
            $sql .= " ORDER BY p.published_at DESC";
        }

        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->query($sql, $params);
    }

    /**
     * Get publications by tag slug
     * @param string $tagSlug Tag slug
     * @param int $limit Limit
     * @param int $offset Offset
     * @return array Publications
     */
    public function getByTagSlug($tagSlug, $limit = 20, $offset = 0) {
        return $this->db->query(
            "SELECT p.*, pt.name as type_name, pt.slug as type_slug,
                    u.full_name as author_name
             FROM publications p
             LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
             LEFT JOIN users u ON p.user_id = u.id
             JOIN publication_tag_relations ptr ON p.id = ptr.publication_id
             JOIN publication_tags t ON ptr.tag_id = t.id
             WHERE t.slug = ? AND p.status = 'published'
             ORDER BY p.published_at DESC
             LIMIT ? OFFSET ?",
            [$tagSlug, $limit, $offset]
        );
    }

    /**
     * Get publications by type slug
     * @param string $typeSlug Type slug
     * @param int $limit Limit
     * @param int $offset Offset
     * @return array Publications
     */
    public function getByTypeSlug($typeSlug, $limit = 20, $offset = 0) {
        return $this->db->query(
            "SELECT p.*, pt.name as type_name, pt.slug as type_slug,
                    u.full_name as author_name
             FROM publications p
             JOIN publication_types pt ON p.publication_type_id = pt.id
             LEFT JOIN users u ON p.user_id = u.id
             WHERE pt.slug = ? AND p.status = 'published'
             ORDER BY p.published_at DESC
             LIMIT ? OFFSET ?",
            [$typeSlug, $limit, $offset]
        );
    }

    /**
     * Get publications by user
     * @param int $userId User ID
     * @param string|null $status Filter by status (null = all)
     * @return array Publications
     */
    public function getByUser($userId, $status = null) {
        $sql = "SELECT p.*, pt.name as type_name, pt.slug as type_slug
                FROM publications p
                LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
                WHERE p.user_id = ?";
        $params = [$userId];

        if ($status !== null) {
            $sql .= " AND p.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY p.created_at DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Get recent publications
     * @param int $limit Limit
     * @return array Publications
     */
    public function getRecent($limit = 10) {
        return $this->db->query(
            "SELECT p.*, pt.name as type_name, u.full_name as author_name
             FROM publications p
             LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
             LEFT JOIN users u ON p.user_id = u.id
             WHERE p.status = 'published'
             ORDER BY p.published_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Get popular publications
     * @param int $limit Limit
     * @return array Publications
     */
    public function getPopular($limit = 10) {
        return $this->db->query(
            "SELECT p.*, pt.name as type_name, u.full_name as author_name
             FROM publications p
             LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
             LEFT JOIN users u ON p.user_id = u.id
             WHERE p.status = 'published'
             ORDER BY p.views_count DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Search publications
     * @param string $query Search query
     * @param array $filters Optional filters
     * @param int $limit Limit
     * @param int $offset Offset
     * @return array Publications
     */
    public function search($query, $filters = [], $limit = 20, $offset = 0) {
        $sql = "SELECT p.*, pt.name as type_name, u.full_name as author_name,
                       MATCH(p.title, p.annotation) AGAINST(? IN NATURAL LANGUAGE MODE) as relevance
                FROM publications p
                LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
                LEFT JOIN users u ON p.user_id = u.id
                WHERE p.status = 'published'
                AND MATCH(p.title, p.annotation) AGAINST(? IN NATURAL LANGUAGE MODE)";

        $params = [$query, $query];

        // Apply filters
        if (!empty($filters['tag_id'])) {
            $sql .= " AND p.id IN (SELECT publication_id FROM publication_tag_relations WHERE tag_id = ?)";
            $params[] = $filters['tag_id'];
        }

        if (!empty($filters['type_id'])) {
            $sql .= " AND p.publication_type_id = ?";
            $params[] = $filters['type_id'];
        }

        $sql .= " ORDER BY relevance DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->query($sql, $params);
    }

    /**
     * Get pending publications for moderation
     * @return array Publications
     */
    public function getPending() {
        return $this->db->query(
            "SELECT p.*, pt.name as type_name, u.full_name as author_name, u.email as author_email
             FROM publications p
             LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
             LEFT JOIN users u ON p.user_id = u.id
             WHERE p.status = 'pending'
             ORDER BY p.created_at ASC"
        );
    }

    /**
     * Approve publication
     * @param int $id Publication ID
     * @return bool Success
     */
    public function approve($id) {
        $result = $this->db->update(
            'publications',
            ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$id]
        );

        if ($result) {
            // Update tag counts
            $this->updateTagCounts($id);
        }

        return $result > 0;
    }

    /**
     * Reject publication
     * @param int $id Publication ID
     * @param string $reason Rejection reason
     * @return bool Success
     */
    public function reject($id, $reason) {
        return $this->db->update(
            'publications',
            ['status' => 'rejected', 'moderation_comment' => $reason],
            'id = ?',
            [$id]
        ) > 0;
    }

    /**
     * Increment view count
     * @param int $id Publication ID
     */
    public function incrementViews($id) {
        $this->db->execute(
            "UPDATE publications SET views_count = views_count + 1 WHERE id = ?",
            [$id]
        );
    }

    /**
     * Increment download count
     * @param int $id Publication ID
     */
    public function incrementDownloads($id) {
        $this->db->execute(
            "UPDATE publications SET downloads_count = downloads_count + 1 WHERE id = ?",
            [$id]
        );
    }

    /**
     * Attach tags to publication
     * @param int $publicationId Publication ID
     * @param array $tagIds Tag IDs
     * @return bool Success
     */
    public function attachTags($publicationId, $tagIds) {
        // Remove existing tags
        $this->db->delete('publication_tag_relations', 'publication_id = ?', [$publicationId]);

        // Add new tags
        foreach ($tagIds as $tagId) {
            $this->db->insert('publication_tag_relations', [
                'publication_id' => $publicationId,
                'tag_id' => $tagId
            ]);
        }

        return true;
    }

    /**
     * Get tags for publication
     * @param int $publicationId Publication ID
     * @return array Tags
     */
    public function getTags($publicationId) {
        return $this->db->query(
            "SELECT t.* FROM publication_tags t
             JOIN publication_tag_relations ptr ON t.id = ptr.tag_id
             WHERE ptr.publication_id = ?
             ORDER BY t.display_order ASC",
            [$publicationId]
        );
    }

    /**
     * Generate URL-friendly slug from title
     * @param string $title Title
     * @return string Slug
     */
    public function generateSlug($title) {
        $slug = mb_strtolower($title, 'UTF-8');

        // Transliterate Cyrillic
        $transliteration = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya'
        ];

        $slug = strtr($slug, $transliteration);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if slug exists
     * @param string $slug Slug
     * @return bool Exists
     */
    private function slugExists($slug) {
        $result = $this->db->queryOne(
            "SELECT id FROM publications WHERE slug = ?",
            [$slug]
        );
        return !empty($result);
    }

    /**
     * Update user publications count
     * @param int $userId User ID
     */
    private function updateUserPublicationsCount($userId) {
        $this->db->execute(
            "UPDATE users SET publications_count = (
                SELECT COUNT(*) FROM publications WHERE user_id = ? AND status = 'published'
            ) WHERE id = ?",
            [$userId, $userId]
        );
    }

    /**
     * Update tag publication counts
     * @param int $publicationId Publication ID
     */
    private function updateTagCounts($publicationId) {
        $tags = $this->getTags($publicationId);
        foreach ($tags as $tag) {
            $this->db->execute(
                "UPDATE publication_tags SET publications_count = (
                    SELECT COUNT(*) FROM publication_tag_relations ptr
                    JOIN publications p ON ptr.publication_id = p.id
                    WHERE ptr.tag_id = ? AND p.status = 'published'
                ) WHERE id = ?",
                [$tag['id'], $tag['id']]
            );
        }
    }

    /**
     * Count published publications
     * @param array $filters Optional filters
     * @return int Count
     */
    public function countPublished($filters = []) {
        $sql = "SELECT COUNT(DISTINCT p.id) as total FROM publications p";
        $wheres = ["p.status = 'published'"];
        $params = [];

        if (!empty($filters['tag_id'])) {
            $sql .= " JOIN publication_tag_relations ptr ON p.id = ptr.publication_id";
            $wheres[] = "ptr.tag_id = ?";
            $params[] = $filters['tag_id'];
        }

        if (!empty($filters['type_id'])) {
            $wheres[] = "p.publication_type_id = ?";
            $params[] = $filters['type_id'];
        }

        $sql .= " WHERE " . implode(" AND ", $wheres);

        $result = $this->db->queryOne($sql, $params);
        return $result['total'] ?? 0;
    }

    /**
     * Get related publications
     * @param int $publicationId Publication ID
     * @param int $limit Limit
     * @return array Related publications
     */
    public function getRelated($publicationId, $limit = 5) {
        // Get current publication tags
        $tags = $this->getTags($publicationId);
        if (empty($tags)) {
            return $this->getRecent($limit);
        }

        $tagIds = array_column($tags, 'id');
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));

        $params = array_merge($tagIds, [$publicationId, $limit]);

        return $this->db->query(
            "SELECT p.*, pt.name as type_name, u.full_name as author_name,
                    COUNT(ptr.tag_id) as tag_matches
             FROM publications p
             LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
             LEFT JOIN users u ON p.user_id = u.id
             JOIN publication_tag_relations ptr ON p.id = ptr.publication_id
             WHERE ptr.tag_id IN ($placeholders)
             AND p.id != ? AND p.status = 'published'
             GROUP BY p.id
             ORDER BY tag_matches DESC, p.views_count DESC
             LIMIT ?",
            $params
        );
    }

    /**
     * Get count of published publications
     * @return int
     */
    public function getPublishedCount() {
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as count FROM publications WHERE status = 'published'"
        );
        return $result['count'] ?? 0;
    }

    /**
     * Get all publications with filters
     * @param array $filters - Array of filters ['status' => 'published']
     * @param int $limit - Number of results to return
     * @return array
     */
    public function getAll($filters = [], $limit = 50) {
        $sql = "SELECT p.*, pt.name as type_name, u.full_name as author_name
                FROM publications p
                LEFT JOIN publication_types pt ON p.publication_type_id = pt.id
                LEFT JOIN users u ON p.user_id = u.id
                WHERE 1=1";
        $params = [];

        if (isset($filters['status'])) {
            $sql .= " AND p.status = ?";
            $params[] = $filters['status'];
        }

        if (isset($filters['type_id'])) {
            $sql .= " AND p.publication_type_id = ?";
            $params[] = $filters['type_id'];
        }

        $sql .= " ORDER BY p.published_at DESC LIMIT ?";
        $params[] = (int)$limit;

        return $this->db->query($sql, $params);
    }
}
