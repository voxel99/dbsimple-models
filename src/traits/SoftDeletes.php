<?php

namespace Jam\Models\Traits;

use Jam\Models\Model;
use Jam\Models\ModelException;

use function request_time;

trait SoftDeletes
{
    protected string $columnDeleted = "deleted_at";

    /**
     * Indicates if the model is currently force deleting.
     *
     * @var bool
     */
    private bool $forceDeleting = false;

    /**
     * Получать коллекцию с удалёнными записями
     */
    protected bool $collectionWithDeleted = false;

    /**
     * Boot the soft deleting trait for a model.
     *
     * @return void
     */
    public function bootSoftDeletes(): void
    {
        $this->on(Model::EVENT_DELETING, function () {
            if (!$this->forceDeleting) {
                $this->{$this->columnDeleted} = request_time("Y-m-d H:i:s");
                $this->update($this->columnDeleted);
                return false;
            }
            return true;
        });
    }

    public function withTrashed($flag = true): static
    {
        $this->collectionWithDeleted = $flag;
        return $this;
    }

    public function useTrashed(): bool
    {
        return true;
    }

    /**
     * Force a hard delete on a soft deleted model.
     *
     * @param bool $withHASRelations
     * @param bool $withBELONGSRelation
     * @return bool
     * @throws ModelException
     */
    public function forceDelete(bool $withHASRelations = false, bool $withBELONGSRelation = false): bool
    {
        $this->forceDeleting = true;

        $deleted = $this->delete($withHASRelations, $withBELONGSRelation);

        $this->forceDeleting = false;

        return $deleted;
    }

    /**
     * Restore a soft-deleted model instance.
     *
     * @return bool
     * @throws ModelException
     */
    public function restore(): bool
    {
        // If the restoring event does not return false, we will proceed with this
        // restore operation. Otherwise, we bail out so the developer will stop
        // the restore totally. We will clear the deleted timestamp and save.
        if ($this->fire(Model::EVENT_RESTORING, $this) === false) {
            return false;
        }

        $this->{$this->columnDeleted} = self::NULL_VALUE;

        $this->update($this->columnDeleted);

        $this->fire(Model::EVENT_RESTORED, $this);

        return true;
    }

    /**
     * Determine if the model instance has been soft-deleted.
     *
     * @return bool
     */
    public function trashed(): bool
    {
        return !empty($this->{$this->columnDeleted}) && ($this->{$this->columnDeleted} !== self::NULL_VALUE);
    }

    /**
     * Register a restoring model event with the dispatcher.
     *
     * @param callable $callback
     * @return void
     */
    public function restoring(callable $callback): void
    {
        $this->on(Model::EVENT_RESTORING, $callback);
    }

    /**
     * Register a restored model event with the dispatcher.
     *
     * @param callable $callback
     * @return void
     */
    public function restored(callable $callback): void
    {
        $this->on(Model::EVENT_RESTORED, $callback);
    }

    /**
     * Determine if the model is currently force deleting.
     *
     * @return bool
     */
    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    /**
     * Get the name of the "deleted at" column.
     *
     * @return string
     */
    public function getDeletedAtColumn(): string
    {
        return $this->columnDeleted;
    }
}
