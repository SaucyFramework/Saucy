<?php

namespace Saucy\Core\Projections\Eloquent;

use App\Models\CannotUpdateReadOnlyField;
use Illuminate\Database\Eloquent\Model;

trait HasReadOnlyFields
{
    protected array $writableFields = [];

    public function writable(array $fields): self
    {
        $this->writableFields = array_merge($this->writableFields, $fields);
        return $this;
    }

    public static function bootHasReadOnlyFields()
    {
        static::updating(function (Model $model) {
            $readOnlyFields = $model->getReadOnlyFields();
            if ($readOnlyFields === null) {
                $violatingFields = array_keys($model->getDirty());
            } else {
                $violatingFields = array_intersect(array_keys($model->getDirty()), $readOnlyFields);
            }
            $violatingFields = array_diff($violatingFields, $model->writableFields ?? []);

            if (count($violatingFields) > 0) {
                throw CannotUpdateReadOnlyField::forFields($violatingFields);
            }

            $model->writableFields = [];
        });
    }

    public function getReadOnlyFields(): ?array
    {
        return $this->readOnlyFields ?? null;
    }
}
