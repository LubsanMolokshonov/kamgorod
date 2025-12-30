<?php
/**
 * Database Wrapper Class
 * Provides convenient methods for database operations using PDO
 */

class Database {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Execute a SELECT query and return all results
     */
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a SELECT query and return single row
     */
    public function queryOne($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Execute INSERT, UPDATE, DELETE queries
     * Returns number of affected rows
     */
    public function execute($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Insert a record and return last insert ID
     */
    public function insert($table, $data) {
        $columns = array_keys($data);
        $values = array_values($data);

        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);

        return $this->pdo->lastInsertId();
    }

    /**
     * Update a record
     * Returns number of affected rows
     */
    public function update($table, $data, $where, $whereParams = []) {
        $setParts = [];
        $values = [];

        foreach ($data as $column => $value) {
            $setParts[] = "{$column} = ?";
            $values[] = $value;
        }

        $setClause = implode(', ', $setParts);
        $values = array_merge($values, $whereParams);

        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);

        return $stmt->rowCount();
    }

    /**
     * Delete a record
     * Returns number of affected rows
     */
    public function delete($table, $where, $whereParams = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($whereParams);

        return $stmt->rowCount();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->pdo->rollBack();
    }

    /**
     * Get PDO instance
     */
    public function getPDO() {
        return $this->pdo;
    }
}
