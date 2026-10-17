<?php

namespace Quill\models;

use model\Model;

abstract class QuillModel extends Model
{
    /** Explicit on-demand relation; uses the current database connection. */
    protected function related(string $class, string $column): ?Model
    {
        return $this->createRelationObject(
            ['model' => $class, 'column' => $column],
            $this->__toArray()
        );
    }

    /** JSON stays a string in persistence, preserving empty objects as {}. */
    protected function decodeDocument(string $column): \stdClass
    {
        $value = $this->getParam($column);
        $document = json_decode((string) $value, false, 512, JSON_THROW_ON_ERROR);
        if (!$document instanceof \stdClass) {
            throw new \UnexpectedValueException($column . ' must contain a JSON object.');
        }
        return $document;
    }

    protected function encodeDocument(string $column, \stdClass $document): void
    {
        $this->{$column} = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
