<?php

namespace App\Models;

use App\Core\App;
use PDO;

abstract class Model
{
    protected string $table;

    public function findById($id)
    {
        $stmt = App::$app->db->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();
        if ($data) {
            $this->load($data);
            return $this;
        }
        return null;
    }

    public function load(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}
