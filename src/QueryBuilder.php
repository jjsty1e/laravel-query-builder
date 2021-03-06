<?php

namespace Jjsty1e\LaravelQueryBuilder;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Jjsty1e\LaravelQueryBuilder\Definition as Def;
use Jjsty1e\LaravelQueryBuilder\Exceptions\ValidationFailedException;

class QueryBuilder
{
    use HasPaginates;

    private $modelName;

    private $condition = [];

    /**
     * @var Builder
     */
    private $builder;

    protected $orderBy = ['id' => 'desc'];

    protected $usingScope = [];

    protected $joinToModels = [];

    protected $joinFromModels = [];

    private $applier;

    private $addedSelect = [];

    private $groupBy;

    public function __construct($modelName)
    {
        $this->modelName = $modelName;
        /** @var Model $model */
        $model = new $this->modelName();
        $this->builder = $model->newQuery();
        $this->builder->select($model->getTable() . '.*');
    }

    /**
     * 是否查询软删除的数据
     *
     * @param $withTrashed
     * @return $this
     */
    public function setWithTrashed($withTrashed): self
    {
        if ($withTrashed) {
            $this->builder->withoutGlobalScope(new SoftDeletingScope());
        }
        return $this;
    }

    public function setCondition(array $condition): self
    {
        $this->condition = $condition;
        return $this;
    }

    /**
     * @throws ValidationFailedException
     */
    public function query(array $params): array
    {
        $this->builder = $this->getQueryBuilder($params);
        foreach ($this->getOrderBy() as $field => $direction) {
            $this->builder->orderBy($field, $direction);
        }
        return $this->groupBy
            ? $this->paginateGrouped($this->builder, $this->getPaginate($params), $this->groupBy)
            : $this->paginate($this->builder, $this->getPaginate($params));
    }

    /**
     * @throws ValidationFailedException
     */
    public function queryAll(array $params): array
    {
        $this->builder = $this->getQueryBuilder($params);

        foreach ($this->getOrderBy() as $field => $direction) {
            $this->builder->orderBy($field, $direction);
        }

        return $this->groupBy
            ? $this->builder->groupBy($this->groupBy)->get()->toArray()
            : $this->builder->get()->toArray();
    }

    /**
     * 传入主键或者参数，查找第一个
     *
     * @param $params
     * @return Builder|Model
     * @throws ValidationFailedException
     */
    public function first($params)
    {
        if (!is_array($params)) {
            $keyName = $this->builder->getModel()->getKeyName();
            $this->setCondition([$keyName => 'term']);
            $params = [$keyName => $params];
        } else {
            $this->setCondition(array_keys($params));
        }

        $this->getQueryBuilder($params);
        return $this->builder->firstOrFail();
    }

    /**
     * 创建查询Builder
     *
     * 如果发现满足条件的数据为空时，可抛出EmptyQueryResultException以中止查询
     *
     * @param array $params
     * @return Builder
     * @throws
     */
    public function getQueryBuilder(array $params): Builder
    {
        $params = $this->trimString($params);

        foreach ($params as &$val) {
            if (is_string($val) || is_numeric($val)) {
                $val = trim($val);
            }
        }
        foreach ($this->getConditionDefinition() as $field => $type) {
            $autoInspect = false;
            if (is_int($field)) {
                $autoInspect = true;
                $field = $type;
            }
            $param = $params[$field] ?? null;
            if (is_null($param)) {
                continue;
            }
            if ($autoInspect) {
                $type = is_array($param) ? Def::TERMS : Def::TERM;
            }
            if (is_scalar($param) && strlen($param) === 0) {
                continue;
            }
            if (is_array($param) && count($param) === 0) {
                continue;
            }
            if (is_array($param) && !in_array($type, [Def::TERMS, Def::RANGE])) {
                throw new ValidationFailedException("params $field format invalid");
            }

            // sql中指定表名，避免在连表时出现字段名冲突
            $sqlField = $this->builder->getModel()->getTable() . '.' . $field;

            switch ($type) {
                case Def::TERM:
                default:
                    $this->builder->where($sqlField, $param);
                    break;
                case Def::TERMS:
                    if (!is_array($param)) {
                        $param = explode(',', $param);
                    }
                    $this->builder->whereIn($sqlField, $param);
                    break;
                case Def::FUZZY:
                    $this->builder->where($sqlField, 'LIKE', "%$param%");
                    break;
                case Def::RANGE:
                    if (is_array($param)) {
                        if (isset($param[0])) {
                            $this->builder->where($sqlField, '>=', $param[0]);
                        }
                        if (isset($param[1])) {
                            $this->builder->where($sqlField, '<=', $param[1]);
                        }
                    }
            }
        }

        $this->applyScope();
        $this->applyJoinTables();

        if ($this->applier) {
            call_user_func($this->applier, $this->builder);
        }

        // 额外的查询
        if ($this->addedSelect) {
            $this->builder->addSelect(array_map(function ($item) {
                return Str::contains($item, '.')
                    ? $item
                    : $this->builder->getModel()->getTable() . '.' . $item;
            }, $this->addedSelect));
        }

        return $this->builder;
    }

    /**
     * 返回排序参数
     *
     * 可覆盖此函数实现自定义排序
     *
     * @return array
     */
    protected function getOrderBy(): array
    {
        $newVal = [];
        foreach ($this->orderBy as $field => $direction) {
            if (!Str::contains($field, '.')) {
                $field = $this->builder->getModel()->getTable() . '.' . $field;
            }
            $newVal[$field] = strtolower($direction);
        }
        return $newVal;
    }

    public function orderBy(array $sorts): self
    {
        $this->orderBy = $sorts;
        return $this;
    }

    /**
     * 返回分页参数
     *
     * 可覆盖此函数实现强制分页（忽略参数中的current_page,per_page）
     *
     * @param array $params
     * @return array
     */
    protected function getPaginate(array $params): array
    {
        return $this->getPageNoPageSize($params);
    }

    private function applyScope()
    {
        foreach ($this->usingScope as $scope) {
            if (method_exists($this->builder->getModel(), 'scope' . ucfirst($scope))) {
                $this->builder->$scope();
            }
        }
    }

    private function applyJoinTables()
    {
        foreach ($this->joinToModels as $joinTo) {
            [$joinTo, $localKey] = (array)$joinTo;
            /** @var Model $other */
            $other = new $joinTo;

            $model = $this->builder->getModel();

            $localKey  = $localKey ?: $other->getTable() . '_id';

            $first = $model->getTable() . '.' . $localKey;
            $second = $other->getTable() . '.' . $model->getKeyName();

            $this->builder->leftJoin($other->getTable(), $first, '=', $second);
        }

        foreach ($this->joinFromModels as $joinFrom) {
            [$joinFrom, $otherKey] = (array)$joinFrom;
            /** @var Model $other */
            $other = new $joinFrom;

            $model = $this->builder->getModel();

            $otherKey = $otherKey ?: $model->getTable() . '_id';

            $first = $model->getTable() . '.' . $model->getKeyName();
            $second = $other->getTable() . '.' . $otherKey;

            $this->builder->leftJoin($other->getTable(), $first, '=', $second);
        }
    }

    /**
     * 指定可用于查询的字段及其查询类型
     *
     * 如果不指定，则过滤所有的查询条件
     *
     * @return array
     */
    protected function getConditionDefinition(): array
    {
        return $this->condition;
    }

    private function trimString(array $params): array
    {
        foreach ($params as &$val) {
            if (is_string($val)) {
                $val = trim($val);
                continue;
            }
            if (is_array($val)) {
                $val = $this->trimString($val);
            }
        }

        unset($val);
        return $params;
    }

    public function select($select)
    {
        $select = is_array($select) ? $select : func_get_args();

        $select = array_map(function ($item) {
            if (Str::contains($item, '.')) {
                return $item;
            }
            return $this->builder->getModel()->getTable() . '.' . $item;
        }, $select);

        $this->builder->select($select);
    }

    public function addSelect($select): QueryBuilder
    {
        $select = is_array($select) ? $select : func_get_args();
        $this->addedSelect = array_merge($this->addedSelect, $select);
        return $this;
    }

    public function apply(callable $applier): self
    {
        $this->applier = $applier;
        return $this;
    }

    public function with(array $relations): self
    {
        $this->builder->with($relations);
        return $this;
    }

    public function groupBy($field): self
    {
        $this->groupBy = $field;
        return $this;
    }

    /**
     * 从当前(a)表连接至其他(b)表：a.b_id = b.id
     * 其中 a.b_id 是 b 表中的主键
     */
    public function joinTo($model, $localKey = null): self
    {
        $this->joinToModels[] = [$model, $localKey];

        return $this;
    }

    /**
     * 从其他(b)表连接至当前(a)表：a.id = b.a_id
     * 其中 b.a_id 是 a 的主键
     */
    public function joinFrom($model, $otherKey = null): self
    {
        $this->joinFromModels[] = [$model, $otherKey];

        return $this;
    }
}
