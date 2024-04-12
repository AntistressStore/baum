<?php

namespace Baum\NestedSet;

class Builder
{
    /**
     * Node instance for reference.
     *
     * @var \Baum\Node
     */
    protected $node;

    /**
     * Array which will hold temporary lft, rgt index values for each scope.
     *
     * @var array
     */
    protected $bounds = [];

    /**
     * Create a new \Baum\SetBuilder class instance.
     *
     * @param \Baum\Node $node
     */
    protected array $rebuilds = [];

    protected array $rebuildsAttributes = [];

    public function __construct($node)
    {
        $this->node = $node;
    }

    protected function setRebuildsAttributes(): array
    {
        $columns = $this->node->getConnection()->getDoctrineSchemaManager()->listTableColumns($this->node->getTable());

        $notNullableColumns = \array_keys(
            \array_filter($columns, fn ($column) => $column->getNotnull() === true && $column->getDefault() === null)
        );

        return $this->rebuildsAttributes = array_unique([...$notNullableColumns,
            $this->node->getParentColumnName(),
            $this->node->getLeftColumnName(),
            $this->node->getRightColumnName(),
            $this->node->getKeyName(),
            $this->node->getDepthColumnName(),
        ]);
    }

    /**
     * Perform the re-calculation of the left and right indexes of the whole
     * nested set tree structure.
     */

    // public function rebuild($force = false)
    // {
    //     $alreadyValid = forward_static_call([get_class($this->node), 'isValidNestedSet']);
    //
    //     // Do not rebuild a valid Nested Set tree structure
    //     if ( ! $force && $alreadyValid) {
    //         return true;
    //     }
    //
    //     // Rebuild lefts and rights for each root node and its children (recursively).
    //     // We go by setting left (and keep track of the current left bound), then
    //     // search for each children and recursively set the left index (while
    //     // incrementing that index). When going back up the recursive chain we start
    //     // setting the right indexes and saving the nodes...
    //     $roots = $this->roots();
    //
    //     $this->node->getConnection()->transaction(function () use ($roots) {
    //         foreach ($roots as $r) {
    //             $this->rebuildBounds($r, 0);
    //         }
    //     });
    // }

    public function rebuild(bool $force = false): bool
    {
        $alreadyValid = forward_static_call([get_class($this->node), 'isValidNestedSet']);

        // Do not rebuild a valid Nested Set tree structure
        if ( ! $force && $alreadyValid) {
            return true;
        }

        // Rebuild lefts and rights for each root node and its children (recursively).
        // We go by setting left (and keep track of the current left bound), then
        // search for each children and recursively set the left index (while
        // incrementing that index). When going back up the recursive chain we start
        // setting the right indexes and saving the nodes...
        $this->setRebuildsAttributes();

        $roots = $this->roots();

        $this->node->getConnection()->transaction(function () use ($roots) {
            foreach ($roots as $r) {
                $this->rebuildBounds($r, 0);
            }

            $updateAttributes = \array_filter($this->rebuildsAttributes, fn ($attr) => $attr != $this->node->getKeyName());

            $this->node->upsert($this->rebuilds,
                $this->node->getKeyName(),
                $updateAttributes
            );
        });
    }

    /**
     * Return all root nodes for the current database table appropiately sorted.
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function roots()
    {
        return $this->node->newQueryWithoutNestedSetScopes()
            ->whereNull($this->node->getQualifiedParentColumnName())
            ->orderBy($this->node->getQualifiedLeftColumnName())
            ->orderBy($this->node->getQualifiedRightColumnName())
            ->orderBy($this->node->getQualifiedKeyName())
            ->get()
        ;
    }

    /**
     * Recompute left and right index bounds for the specified node and its
     * children (recursive call). Fill the depth column too.
     */

    // public function rebuildBounds($node, $depth = 0)
    // {
    //     $k = $this->scopedKey($node);
    //
    //     $node->setAttribute($node->getLeftColumnName(), $this->getNextBound($k));
    //     $node->setAttribute($node->getDepthColumnName(), $depth);
    //
    //     foreach ($this->children($node) as $child) {
    //         $this->rebuildBounds($child, $depth + 1);
    //     }
    //
    //     $node->setAttribute($node->getRightColumnName(), $this->getNextBound($k));
    //
    //     $node->save();
    // }

    public function rebuildBounds($node, int $depth = 0): void
    {
        $k = $this->scopedKey($node);

        $node->setAttribute($node->getLeftColumnName(), $this->getNextBound($k));
        $node->setAttribute($node->getDepthColumnName(), $depth);

        foreach ($this->children($node) as $child) {
            $this->rebuildBounds($child, $depth + 1);
        }

        $node->setAttribute($node->getRightColumnName(), $this->getNextBound($k));

        $this->rebuilds[] = $node->only($this->rebuildsAttributes);
    }

    /**
     * Return all children for the specified node.
     *
     * @param Baum\Node $node
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function children($node)
    {
        // $query = $this->node->newQuery();
        $query = $this->node->newQueryWithoutNestedSetScopes();

        $query->where($this->node->getQualifiedParentColumnName(), '=', $node->getKey());

        // We must also add the scoped column values to the query to compute valid
        // left and right indexes.
        foreach ($this->scopedAttributes($node) as $fld => $value) {
            $query->where($this->node->qualifyColumn($fld), '=', $value);
        }

        $query->orderBy($this->node->getQualifiedLeftColumnName());
        $query->orderBy($this->node->getQualifiedRightColumnName());
        $query->orderBy($this->node->getQualifiedKeyName());

        return $query->get();
    }

    /**
     * Return an array of the scoped attributes of the supplied node.
     *
     * @param Baum\Node $node
     *
     * @return array
     */
    protected function scopedAttributes($node)
    {
        $keys = $this->node->getScopedColumnNames();

        if (count($keys) == 0) {
            return [];
        }

        $values = array_map(function ($column) use ($node) {
            return $node->getAttribute($column);
        }, $keys);

        return array_combine($keys, $values);
    }

    /**
     * Return a string-key for the current scoped attributes. Used for index
     * computing when a scope is defined (acsts as an scope identifier).
     *
     * @param Baum\Node $node
     *
     * @return string
     */
    protected function scopedKey($node)
    {
        $attributes = $this->scopedAttributes($node);

        $output = [];

        foreach ($attributes as $fld => $value) {
            $output[] = $this->node->qualifyColumn($fld).'='.(is_null($value) ? 'NULL' : $value);
        }

        // NOTE: Maybe an md5 or something would be better. Should be unique though.
        return implode(',', $output);
    }

    /**
     * Return next index bound value for the given key (current scope identifier).
     *
     * @param string $key
     *
     * @return int
     */
    protected function getNextBound($key)
    {
        if (array_key_exists($key, $this->bounds) === false) {
            $this->bounds[$key] = 0;
        }

        $this->bounds[$key] = $this->bounds[$key] + 1;

        return $this->bounds[$key];
    }
}
