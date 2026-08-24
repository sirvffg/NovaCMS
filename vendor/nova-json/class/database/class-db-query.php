<?php
/**
 * Nova JSON API: Nova_DB_Query
 *
 * 链式查询构建器，用面向对象的方式构建 SELECT 查询。
 *
 * 用法：
 *   $q = new Nova_DB_Query();
 *   $results = $q->from('instant')
 *                 ->where('status', 1)
 *                 ->orderBy('id', 'DESC')
 *                 ->limit(10)
 *                 ->get();
 *
 *   $row = $q->from('users')->where('id', 1)->first();
 *   $count = $q->from('posts')->where('status', 'publish')->count();
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_DB_Query {

    protected $pdo;
    protected $table      = '';
    protected $select     = ['*'];
    protected $joins      = [];
    protected $wheres     = [];
    protected $whereRaw   = '';
    protected $orderBy    = [];
    protected $groupBy    = [];
    protected $having     = [];
    protected $limitVal   = null;
    protected $offsetVal  = null;
    protected $params     = [];
    protected $distinct   = false;
    protected $bindings   = [];

    public function __construct() {
        $this->pdo = getDB();
    }

    /**
     * 设置查询表名
     * @param string $table 表名
     * @return $this
     */
    public function from($table) {
        $this->table = $table;
        return $this;
    }

    /**
     * 别名 table()
     * @param string $table 表名
     * @return $this
     */
    public function table($table) {
        return $this->from($table);
    }

    /**
     * 设置要查询的字段
     * @param array|string $columns 字段列表
     * @return $this
     */
    public function select($columns = ['*']) {
        $this->select = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    /**
     * DISTINCT 查询
     * @param bool $flag
     * @return $this
     */
    public function distinct($flag = true) {
        $this->distinct = $flag;
        return $this;
    }

    /**
     * JOIN 子句
     * @param string $table   要 JOIN 的表
     * @param string $on      ON 条件
     * @param string $type    JOIN 类型（INNER, LEFT, RIGHT 等）
     * @return $this
     */
    public function join($table, $on, $type = 'INNER') {
        $this->joins[] = strtoupper($type) . " JOIN `{$table}` ON {$on}";
        return $this;
    }

    /**
     * LEFT JOIN
     * @param string $table
     * @param string $on
     * @return $this
     */
    public function leftJoin($table, $on) {
        return $this->join($table, $on, 'LEFT');
    }

    /**
     * RIGHT JOIN
     * @param string $table
     * @param string $on
     * @return $this
     */
    public function rightJoin($table, $on) {
        return $this->join($table, $on, 'RIGHT');
    }

    /**
     * WHERE 条件
     * @param string|array $column 字段名或条件数组
     * @param mixed        $value  值（省略时视为 = TRUE）
     * @param string       $op     运算符（默认 =）
     * @param string       $glue   AND / OR
     * @return $this
     */
    public function where($column, $value = null, $op = '=', $glue = 'AND') {
        if (is_array($column)) {
            foreach ($column as $col => $val) {
                $this->wheres[] = [
                    'type'   => 'basic',
                    'column' => $col,
                    'value'  => $val,
                    'op'     => '=',
                    'glue'   => $glue,
                ];
            }
        } else {
            $this->wheres[] = [
                'type'   => 'basic',
                'column' => $column,
                'value'  => $value,
                'op'     => $op,
                'glue'   => $glue,
            ];
        }
        return $this;
    }

    /**
     * OR WHERE
     * @param string $column
     * @param mixed  $value
     * @param string $op
     * @return $this
     */
    public function orWhere($column, $value = null, $op = '=') {
        return $this->where($column, $value, $op, 'OR');
    }

    /**
     * WHERE IN
     * @param string $column 字段名
     * @param array  $values 值列表
     * @return $this
     */
    public function whereIn($column, array $values) {
        $this->wheres[] = [
            'type'   => 'in',
            'column' => $column,
            'values' => $values,
            'glue'   => 'AND',
        ];
        return $this;
    }

    /**
     * WHERE NOT IN
     * @param string $column
     * @param array  $values
     * @return $this
     */
    public function whereNotIn($column, array $values) {
        $this->wheres[] = [
            'type'   => 'not_in',
            'column' => $column,
            'values' => $values,
            'glue'   => 'AND',
        ];
        return $this;
    }

    /**
     * WHERE BETWEEN
     * @param string $column
     * @param mixed  $start
     * @param mixed  $end
     * @return $this
     */
    public function whereBetween($column, $start, $end) {
        $this->wheres[] = [
            'type'  => 'between',
            'column' => $column,
            'start' => $start,
            'end'   => $end,
            'glue'  => 'AND',
        ];
        return $this;
    }

    /**
     * WHERE NULL
     * @param string $column
     * @return $this
     */
    public function whereNull($column) {
        $this->wheres[] = [
            'type'   => 'null',
            'column' => $column,
            'glue'   => 'AND',
        ];
        return $this;
    }

    /**
     * WHERE NOT NULL
     * @param string $column
     * @return $this
     */
    public function whereNotNull($column) {
        $this->wheres[] = [
            'type'   => 'not_null',
            'column' => $column,
            'glue'   => 'AND',
        ];
        return $this;
    }

    /**
     * LIKE 查询
     * @param string $column
     * @param string $value
     * @param string $side  匹配模式（both, left, right）
     * @return $this
     */
    public function like($column, $value, $side = 'both') {
        switch ($side) {
            case 'left':  $value = "%{$value}"; break;
            case 'right': $value = "{$value}%"; break;
            default:      $value = "%{$value}%"; break;
        }
        return $this->where($column, $value, 'LIKE');
    }

    /**
     * OR LIKE
     * @param string $column
     * @param string $value
     * @param string $side
     * @return $this
     */
    public function orLike($column, $value, $side = 'both') {
        switch ($side) {
            case 'left':  $value = "%{$value}"; break;
            case 'right': $value = "{$value}%"; break;
            default:      $value = "%{$value}%"; break;
        }
        return $this->where($column, $value, 'LIKE', 'OR');
    }

    /**
     * 原始 WHERE 子句
     * @param string $sql    原始 SQL
     * @param array  $params 绑定参数
     * @return $this
     */
    public function whereRaw($sql, $params = []) {
        $this->wheres[] = [
            'type'   => 'raw',
            'sql'    => $sql,
            'params' => $params,
            'glue'   => 'AND',
        ];
        return $this;
    }

    /**
     * ORDER BY
     * @param string $column 字段名
     * @param string $direction ASC / DESC
     * @return $this
     */
    public function orderBy($column, $direction = 'ASC') {
        $this->orderBy[] = "`{$column}` " . strtoupper($direction);
        return $this;
    }

    /**
     * 原始 ORDER BY
     * @param string $sql
     * @return $this
     */
    public function orderByRaw($sql) {
        $this->orderBy[] = $sql;
        return $this;
    }

    /**
     * GROUP BY
     * @param string|array $columns
     * @return $this
     */
    public function groupBy($columns) {
        $columns = is_array($columns) ? $columns : func_get_args();
        $this->groupBy = array_merge($this->groupBy, $columns);
        return $this;
    }

    /**
     * HAVING
     * @param string $column
     * @param mixed  $value
     * @param string $op
     * @return $this
     */
    public function having($column, $value, $op = '=') {
        $this->having[] = "{$column} {$op} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * LIMIT
     * @param int $limit
     * @return $this
     */
    public function limit($limit) {
        $this->limitVal = (int)$limit;
        return $this;
    }

    /**
     * OFFSET
     * @param int $offset
     * @return $this
     */
    public function offset($offset) {
        $this->offsetVal = (int)$offset;
        return $this;
    }

    /**
     * 分页（自动计算 offset）
     * @param int $page     页码，从 1 开始
     * @param int $perPage  每页数量
     * @return $this
     */
    public function page($page, $perPage = 20) {
        $page = max(1, (int)$page);
        $this->limitVal = (int)$perPage;
        $this->offsetVal = ($page - 1) * (int)$perPage;
        return $this;
    }

    // ── 执行查询 ──

    /**
     * 构建 SELECT SQL
     * @return string
     */
    protected function buildSelect() {
        $sql = $this->distinct ? 'SELECT DISTINCT ' : 'SELECT ';
        $sql .= implode(', ', $this->select);
        $sql .= " FROM `{$this->table}`";

        foreach ($this->joins as $join) {
            $sql .= " {$join}";
        }

        $whereSql = $this->buildWhere();
        if ($whereSql) {
            $sql .= " WHERE {$whereSql}";
        }

        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if (!empty($this->having)) {
            $sql .= ' HAVING ' . implode(' AND ', $this->having);
        }

        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limitVal !== null) {
            $sql .= " LIMIT {$this->limitVal}";
        }

        if ($this->offsetVal !== null) {
            $sql .= " OFFSET {$this->offsetVal}";
        }

        return $sql;
    }

    /**
     * 构建 WHERE 子句
     * @return string
     */
    protected function buildWhere() {
        if (empty($this->wheres)) {
            return '';
        }

        $parts = [];
        foreach ($this->wheres as $i => $where) {
            $glue = $i === 0 ? '' : " {$where['glue']} ";

            switch ($where['type']) {
                case 'basic':
                    $parts[] = "{$glue}`{$where['column']}` {$where['op']} ?";
                    $this->bindings[] = $where['value'];
                    break;

                case 'in':
                    $placeholders = implode(', ', array_fill(0, count($where['values']), '?'));
                    $parts[] = "{$glue}`{$where['column']}` IN ({$placeholders})";
                    $this->bindings = array_merge($this->bindings, $where['values']);
                    break;

                case 'not_in':
                    $placeholders = implode(', ', array_fill(0, count($where['values']), '?'));
                    $parts[] = "{$glue}`{$where['column']}` NOT IN ({$placeholders})";
                    $this->bindings = array_merge($this->bindings, $where['values']);
                    break;

                case 'between':
                    $parts[] = "{$glue}`{$where['column']}` BETWEEN ? AND ?";
                    $this->bindings[] = $where['start'];
                    $this->bindings[] = $where['end'];
                    break;

                case 'null':
                    $parts[] = "{$glue}`{$where['column']}` IS NULL";
                    break;

                case 'not_null':
                    $parts[] = "{$glue}`{$where['column']}` IS NOT NULL";
                    break;

                case 'raw':
                    $parts[] = "{$glue}({$where['sql']})";
                    $this->bindings = array_merge($this->bindings, $where['params']);
                    break;
            }
        }

        return ltrim(implode('', $parts));
    }

    /**
     * 获取多行结果
     * @return array
     */
    public function get() {
        $sql = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->reset();
        return $results;
    }

    /**
     * 获取第一行
     * @return array|null
     */
    public function first() {
        $this->limitVal = 1;
        $sql = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $this->reset();
        return $row;
    }

    /**
     * 获取单列值
     * @param string $column 字段名
     * @return mixed|null
     */
    public function value($column) {
        $this->select = [$column];
        $this->limitVal = 1;
        $sql = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $val = $stmt->fetchColumn();
        $this->reset();
        return $val !== false ? $val : null;
    }

    /**
     * 获取单列值列表
     * @param string $column 字段名
     * @return array
     */
    public function pluck($column) {
        $this->select = [$column];
        $sql = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->reset();
        return $results;
    }

    /**
     * 计数
     * @param string $column
     * @return int
     */
    public function count($column = '*') {
        $this->select = ["COUNT({$column})"];
        $sql = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $count = (int)$stmt->fetchColumn();
        $this->reset();
        return $count;
    }

    /**
     * 求和
     * @param string $column
     * @return float
     */
    public function sum($column) {
        return $this->aggregate('SUM', $column);
    }

    /**
     * 平均值
     * @param string $column
     * @return float
     */
    public function avg($column) {
        return $this->aggregate('AVG', $column);
    }

    /**
     * 最大值
     * @param string $column
     * @return float
     */
    public function max($column) {
        return $this->aggregate('MAX', $column);
    }

    /**
     * 最小值
     * @param string $column
     * @return float
     */
    public function min($column) {
        return $this->aggregate('MIN', $column);
    }

    /**
     * 聚合函数
     * @param string $func
     * @param string $column
     * @return float
     */
    protected function aggregate($func, $column) {
        $this->select = ["{$func}({$column})"];
        $sql = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $val = $stmt->fetchColumn();
        $this->reset();
        return (float)$val;
    }

    /**
     * 判断是否存在记录
     * @return bool
     */
    public function exists() {
        return $this->count() > 0;
    }

    /**
     * 判断是否不存在记录
     * @return bool
     */
    public function doesntExist() {
        return !$this->exists();
    }

    /**
     * 分页查询（返回带分页信息的结果集）
     * @param int $perPage 每页数量
     * @param int $page    当前页码（默认自动从请求获取）
     * @return array ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'pages' => 0]
     */
    public function paginate($perPage = 20, $page = null) {
        $page = $page ?: (isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1);

        // 先获取总数
        $countSelect = $this->select;
        $this->select = ['COUNT(*)'];
        $countSql = $this->buildSelect();
        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute($this->bindings);
        $total = (int)$stmt->fetchColumn();

        // 恢复 select 并分页获取数据
        $this->select = $countSelect;
        $bindings = $this->bindings;
        $this->bindings = [];
        $this->limitVal = (int)$perPage;
        $this->offsetVal = ($page - 1) * (int)$perPage;
        $dataSql = $this->buildSelect();
        $stmt = $this->pdo->prepare($dataSql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->reset();

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => (int)$perPage,
            'pages'    => (int)ceil($total / max(1, (int)$perPage)),
        ];
    }

    /**
     * 获取构建的 SQL（调试用）
     * @return string
     */
    public function toSql() {
        $sql = $this->buildSelect();
        $this->reset();
        return $sql;
    }

    /**
     * 获取 SQL 及绑定参数（调试用）
     * @return array ['sql' => '', 'bindings' => []]
     */
    public function toSqlWithBindings() {
        $bindings = $this->bindings;
        $sql = $this->buildSelect();
        $this->reset();
        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /**
     * 重置查询状态
     */
    protected function reset() {
        $this->select    = ['*'];
        $this->joins     = [];
        $this->wheres    = [];
        $this->whereRaw  = '';
        $this->orderBy   = [];
        $this->groupBy   = [];
        $this->having    = [];
        $this->limitVal  = null;
        $this->offsetVal = null;
        $this->params    = [];
        $this->distinct  = false;
        $this->bindings  = [];
        $this->table     = '';
    }
}
