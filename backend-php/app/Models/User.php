<?php

namespace App\Models;

use App\Core\App;

class User extends Model
{
    protected string $table = 'users';

    public int $id;
    public string $name;
    public string $email;
    public string $password_hash;
    public string $role;
    public int $active;
    public int $must_change_password = 0;
    public ?string $created_at;
    public ?string $updated_at;

    public function findByEmail(string $email)
    {
        $stmt = App::$app->db->prepare("SELECT * FROM {$this->table} WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $data = $stmt->fetch();
        if ($data) {
            $this->load($data);
            return $this;
        }
        return null;
    }

    public static function all(): array
    {
        $stmt = App::$app->db->prepare("
            SELECT u.*, COUNT(i.id) AS instance_count
            FROM users u
            LEFT JOIN instances i ON i.user_id = u.id
            GROUP BY u.id
            ORDER BY u.id DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function countAll(): int
    {
        $stmt = App::$app->db->prepare("SELECT COUNT(*) FROM users");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public static function emailExists(string $email, ?int $ignoreId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM users WHERE email = :email";
        $params = ['email' => $email];
        if ($ignoreId !== null) {
            $sql .= " AND id <> :id";
            $params['id'] = $ignoreId;
        }

        $stmt = App::$app->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function findActiveByIdentity(string $identity): array
    {
        $identity = strtolower(trim($identity));
        if ($identity === '') return [];

        if (str_contains($identity, '@')) {
            $stmt = App::$app->db->prepare('SELECT id, name, email FROM users WHERE active = 1 AND LOWER(email) = :identity');
        } else {
            $stmt = App::$app->db->prepare('SELECT id, name, email FROM users WHERE active = 1 AND LOWER(name) = :identity ORDER BY id');
        }
        $stmt->execute(['identity' => $identity]);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $stmt = App::$app->db->prepare("
            INSERT INTO users (name, email, password_hash, role, active, must_change_password)
            VALUES (:name, :email, :password_hash, :role, :active, :must_change_password)
        ");
        $stmt->execute([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role' => self::validRole($data['role'] ?? 'user'),
            'active' => !empty($data['active']) ? 1 : 0,
            'must_change_password' => !empty($data['must_change_password']) ? 1 : 0
        ]);

        return (int) App::$app->db->pdo->lastInsertId();
    }

    public function update(array $data): void
    {
        $fields = [];
        $params = ['id' => $this->id];

        foreach (['name', 'email', 'role', 'active', 'must_change_password'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $field === 'role' ? self::validRole($data[$field]) : $data[$field];
            }
        }

        if (!empty($data['password'])) {
            $fields[] = "password_hash = :password_hash";
            $params['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (!$fields) {
            return;
        }

        $stmt = App::$app->db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id");
        $stmt->execute($params);
    }

    public function delete(): void
    {
        $stmt = App::$app->db->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute(['id' => $this->id]);
    }

    public static function validRole(string $role): string
    {
        return in_array($role, ['admin', 'user'], true) ? $role : 'user';
    }
}
