<?php

namespace LanguageServer\CodeDB;

class Repository {
    private $pdo;

    public function __construct($rootPath) {
        if (!\file_exists("$rootPath/.phpls")) {
            \mkdir("$rootPath/.phpls", 0775, true);
        }
        $this->pdo = new \PDO("sqlite:$rootPath/.phpls/codedb.sqlite");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        /*$this->pdo->exec('DROP TABLE IF EXISTS "files"');
        $this->pdo->exec('DROP TABLE IF EXISTS "symbols"');
        $this->pdo->exec('DROP TABLE IF EXISTS "references"');*/

        $this->createTable('files', [
            'id' => 'INTEGER PRIMARY KEY',
            'uri' => 'TEXT',
            'hash' => 'TEXT',
        ]);

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS `files_uri` ON `files` ( `uri` )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS `files_hash` ON `files` ( `hash` )');
        
        $this->createTable('symbols', [
            'id' => 'INTEGER PRIMARY KEY',
            'parent_id' => 'INTEGER',
            'type' => 'INTEGER',
            'description' => 'TEXT',
            'name' => 'TEXT',
            'fqn' => 'TEXT',
            'file_id' => 'INTEGER',
            'range_start_line' => 'INTEGER',
            'range_start_character' => 'INTEGER',
            'range_end_line' => 'INTEGER',
            'range_end_character' => 'INTEGER',
        ]);

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS `symbols_parent_id` ON `symbols` ( `parent_id` )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS `symbols_fqn` ON `symbols` ( `fqn` )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS `symbols_file_id` ON `symbols` ( `file_id` )');
        
        $this->createTable('references', [
            'id' => 'INTEGER PRIMARY KEY',
            'type' => 'INTEGER',
            'fqn' => 'TEXT',
            'symbol_id' => 'INTEGER',
            'file_id' => 'INTEGER',
            'range_start_line' => 'INTEGER',
            'range_start_character' => 'INTEGER',
            'range_end_line' => 'INTEGER',
            'range_end_character' => 'INTEGER',
        ]);

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS `references_fqn` ON `references` ( `fqn` )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS `references_symbol_id` ON `references` ( `symbol_id` )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS `references_file_id` ON `references` ( `file_id` )');
    }

    private function insert(string $table, array $values) {
        $fieldnames = [];
        $placeholders = [];
        foreach(array_keys($values) as $key) {
            $fieldnames[] = "\"$key\"";
        }

        foreach(array_keys($values) as $key) {
            $placeholders[] = ":$key";
        }

        $fieldnames = implode(', ', $fieldnames);
        $placeholders = implode(', ', $placeholders);

        $qry = "INSERT INTO \"$table\" ($fieldnames) VALUES ($placeholders) ";

        $stmt = $this->pdo->prepare($qry);
        $stmt->execute($values);

        $id = $this->pdo->lastInsertId();
        return $id;
    }

    /**
     * Creates a table
     */
    private function createTable(string $name, array $fields) {
        $qry = 'CREATE TABLE IF NOT EXISTS "'.$name.'" (';
        $first = true;
        foreach($fields as $k => $v) {
            if (!$first) {
                $qry .= ', ';
            }
            $first = false;
            $qry .= '"'.$k.'" ' . $v;
        }
        $qry .= ')';
        $this->pdo->exec($qry);
    }

    public function select(string $table, string $class, string $where) {
        $stmt = $this->pdo->prepare('SELECT * FROM "'.$table.'" WHERE '.$where);
        $stmt->execute();
        $objs = [];
        while(($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $obj = new $class;
            foreach($row as $k => $v) {
                $obj->{$k} = $v;
            }
            $objs[] = $obj;
        }
        return $objs;
    }

    public function selectFirst(string $table, string $class, string $where) {
        $stmt = $this->pdo->prepare('SELECT * FROM "'.$table.'" WHERE '.$where.' LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $obj = new $class;
        foreach($row as $k => $v) {
            $obj->{$k} = $v;
        }
        return $obj;
    }

    public function getSymbolRefCount($uri) {
        $stmt = $this->pdo->prepare('
        SELECT
            symbols.*,
            count("references"."id") AS "ref_count"
        FROM
            "files"
        JOIN
            "symbols" ON "symbols"."file_id" = "files"."id"
        LEFT JOIN
            "symbols" AS s2 ON s2.id = symbols.parent_id
        LEFT JOIN
            "references" ON "references"."symbol_id" = "symbols"."id"
        WHERE
            "files"."uri" = :uri AND
            (
                s2.type IS NULL OR
                s2.type IN (1,2,4,6)
            )
        GROUP BY
            "symbols"."id"
        ');
        $stmt->execute(['uri' => $uri]);
        $objs = [];
        while(($row = $stmt->fetchObject(Symbol::class)) !== false) {
            $objs[] = $row;
        }
        return $objs;
    }

    public function getSymbolsByUri($uri) {
        $stmt = $this->pdo->prepare('
        SELECT
            symbols.*,
            files.uri,
            s2.fqn AS parent_fqn
        FROM
            symbols
        JOIN
            files ON files.id = symbols.file_id
        LEFT JOIN
            symbols AS s2 on symbols.parent_id = s2.id
        WHERE
            uri = \''.$uri.'\' AND
            (
                s2.type IS NULL OR
                s2.type IN (1,2,4,6)
            )
        ');

        $stmt->execute();
        $objs = [];
        while(($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $obj = new Symbol;
            foreach($row as $k => $v) {
                $obj->{$k} = $v;
            }
            $objs[] = $obj;
        }
        return $objs;
    }

    public function getSymbolsByName($name) {
        $stmt = $this->pdo->prepare('
        SELECT
            symbols.*,
            files.uri,
            s2.fqn AS parent_fqn
        FROM
            symbols
        JOIN
            files ON files.id = symbols.file_id
        LEFT JOIN
            symbols AS s2 on symbols.parent_id = s2.id
        WHERE
            symbols.name like :name AND
            (
                s2.type IS NULL OR
                s2.type IN (1,2,4,6)
            )
        ');

        $stmt->execute([
            'name' => "%$name%"
        ]);
        $objs = [];
        while(($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $obj = new Symbol;
            foreach($row as $k => $v) {
                $obj->{$k} = $v;
            }
            $objs[] = $obj;
        }
        return $objs;
    }

    public function getReferenceAtPosition($uri, $line, $character) {
        $stmt = $this->pdo->prepare('
        SELECT
            "references"."id",
            "references"."range_start_line",
            "references"."range_start_character",
            "references"."range_end_line",
            "references"."range_end_character",
            "references"."symbol_id",
            "references"."fqn"
        FROM
            "references"
        JOIN
            "files" ON "references"."file_id" = "files"."id"
        WHERE
            "files"."uri" = :uri AND
            (
                (
                    ("references"."range_start_line" = :line AND "references"."range_start_character" <= :character) OR
                    ("references"."range_start_line" < :line)
                )
                AND
                (
                    ("references"."range_end_line" = :line AND "references"."range_end_character" >= :character) OR
                    ("references"."range_end_line" > :line)
                )
            )
            ');
        $stmt->execute(['uri' => $uri, 'line' => $line, 'character' => $character]);
        return $stmt->fetchObject(Reference::class);
    }

    public function getSymbolAtPosition($uri, $line, $character) {
        $stmt = $this->pdo->prepare('
        SELECT
            symbols.*
        FROM
            "symbols"
        JOIN
            "files" ON "symbols"."file_id" = "files"."id"
        WHERE
            "files"."uri" = :uri AND
            (
                (
                    ("symbols"."range_start_line" = :line AND "symbols"."range_start_character" <= :character) OR
                    ("symbols"."range_start_line" < :line)
                )
                AND
                (
                    ("symbols"."range_end_line" = :line AND "symbols"."range_end_character" >= :character) OR
                    ("symbols"."range_end_line" > :line)
                )
            )
        ');
        $stmt->execute(['uri' => $uri, 'line' => $line, 'character' => $character]);
        return $stmt->fetchObject(Symbol::class);
    }

    public function getSymbolById($id) {
        return $this->selectFirst('symbols', Symbol::class, '"id" = '.$id);
    }

    public function getFileById($id) {
        return $this->selectFirst('files', File::class, '"id" = '.$id);
    }

    public function getReferencesBySymbolId($id) {
        $stmt = $this->pdo->prepare('
        SELECT
            uri,
            range_start_line,
            range_start_character,
            range_end_line,
            range_end_character
        FROM
            "references"
        JOIN
            files on file_id = files.id
        WHERE
            symbol_id = :symbol_id'
        );
        $stmt->execute([
            'symbol_id' => $id
        ]);
        $objs = [];
        while(($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $obj = new Reference;
            foreach($row as $k => $v) {
                $obj->{$k} = $v;
            }
            $objs[] = $obj;
        }
        return $objs;
    }

    public function getUnresolvedReferences() {
        return $this->select('references', Reference::class, '"symbol_id" IS NULL');
    }

    public function resolveReferences() {
        $this->pdo->exec('
        UPDATE "references" SET symbol_id = (SELECT id FROM symbols WHERE symbols.fqn = "references"."fqn")
        WHERE symbol_id IS NULL AND EXISTS (SELECT id FROM symbols WHERE symbols.fqn = "references"."fqn")
        ');
        return;
        $start = microtime(true);
        // first resolve non-members
        $references = $this->getUnresolvedReferences();
        foreach($references as $ref) {
            $fqn = $ref->fqn;
            if (strpos($fqn, '::') === false) {
                if (isset($this->fqnMap[$fqn])) {
                    $target = $this->fqnMap[$fqn];
                    $ref->target = $target;
                }
                else if (strpos($fqn, '()') !== false) {
                    // function
                    $pos = strrpos($fqn, '\\');
                    if ($pos > 0) {
                        $newfqn = substr($fqn, $pos);
                        if (isset($this->fqnMap[$newfqn])) {
                            $target = $this->fqnMap[$newfqn];
                            $ref->target = $target;
                        }
                    }
                }
            }
        }

        // then resolve members
        foreach($references as $ref) {
            $fqn = $ref->fqn;
            if (strpos($fqn, '::') !== false) {
                if (isset($this->fqnMap[$fqn])) {
                    $target = $this->fqnMap[$fqn];
                    $ref->target = $target;
                }
                else {
                    // is class member
                    $parts = explode('::', $fqn, 2);
                    $clsName = $parts[0];
                    $symName = $parts[1];
                    if (isset($this->fqnMap[$clsName])) {
                        $cls = $this->fqnMap[$clsName];
                        if (!$cls instanceof Class_) {
                            continue;
                        }
                    }
                    else {
                        continue;
                    }

                    if (substr($symName, 0, 1) === '$') {
                        // field
                        $symName = substr($symName, 1);
                        $found = $cls->findField($symName);
                    }
                    else if (substr($symName, -2) === '()') {
                        // method
                        $symName = substr($symName, 0, -2);
                        $found = $cls->findMethod($symName);
                    }
                    else {
                        // const
                        $symName = substr($symName, 1);
                        $found = $cls->findConstant($symName);
                    }

                    if ($found) {
                        $ref->target = $found;
                    }
                }
            }
        }
    }

    public function addReference(Reference $ref) {
        if ($ref->id) return;

        $ref->id = $this->insert('references', [
            'fqn' => $ref->fqn,
            'type' => $ref->type,
            'symbol_id' => $ref->symbol_id,
            'file_id' => $ref->file_id,
            'range_start_line' => $ref->range_start_line,
            'range_start_character' => $ref->range_start_character,
            'range_end_line' => $ref->range_end_line,
            'range_end_character' => $ref->range_end_character,
        ]);
    }

    public function removeReference(Reference $ref) {
        if (!$ref->id) return;

        $stmt = $this->pdo->prepare('DELETE FROM "references" WHERE "id" = :id');
        $stmt->execute(['id' => $ref->id]);
    }

    public function removeFile($uri) {
        $file = $this->getFile($uri);
        if (!$file) return;

        $stmt = $this->pdo->prepare('DELETE FROM "files" WHERE id = :id');
        $stmt->execute(['id' => $file->id]);

        $stmt = $this->pdo->prepare('DELETE FROM "references" WHERE file_id = :file_id');
        $stmt->execute(['file_id' => $file->id]);

        $stmt = $this->pdo->prepare('UPDATE "references" SET symbol_id = NULL WHERE "references"."symbol_id" IS NOT NULL AND (SELECT file_id FROM symbols WHERE symbols.id = "references"."symbol_id") = :file_id');
        $stmt->execute(['file_id' => $file->id]);

        $stmt = $this->pdo->prepare('DELETE FROM "symbols" WHERE file_id = :file_id');
        $stmt->execute(['file_id' => $file->id]);
    }

    public function addFile(File $file) {
        if ($file->id) return;

        $file->id = $this->insert('files', [
            'uri' => $file->uri,
            'hash' => $file->hash
        ]);
    }

    public function getFile(string $uri) {
        return $this->selectFirst('files', File::class, '"uri" = \''.$uri.'\'');
    }

    public function addSymbol(Symbol $symbol) {
        if ($symbol->id) return;

        $symbol->id = $this->insert('symbols', [
            'parent_id' => $symbol->parent_id,
            'description' => $symbol->description,
            'name' => $symbol->name,
            'fqn' => $symbol->fqn,
            'type' => $symbol->type,
            'file_id' => $symbol->file_id,
            'range_start_line' => $symbol->range_start_line,
            'range_start_character' => $symbol->range_start_character,
            'range_end_line' => $symbol->range_end_line,
            'range_end_character' => $symbol->range_end_character,
        ]);
    }

    public function hasFileWithHash(string $uri, string $hash) {
        $stmt = $this->pdo->prepare('SELECT 1 FROM "files" WHERE "uri" = :uri AND "hash" = :hash LIMIT 1');
        $stmt->execute([
            'uri' => $uri,
            'hash' => $hash
        ]);
        return $stmt->fetch() !== false;
    }

    public function hasFile(string $uri) {
        $stmt = $this->pdo->prepare('SELECT 1 FROM "files" WHERE "uri" = :uri LIMIT 1');
        $stmt->execute([
            'uri' => $uri
        ]);
        return $stmt->fetch() !== false;
    }

    public function startIndex() {
        $this->pdo->beginTransaction();
    }

    public function finishIndex() {
        $this->pdo->commit();
    }

    public function beginTransaction() {
        $this->pdo->beginTransaction();
    }

    public function rollback() {
        $this->pdo->rollback();
    }

    public function commit() {
        $this->pdo->commit();
    }
}
