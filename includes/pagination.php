<?php
/**
 * Server-side pagination, shared by every admin table.
 *
 * One helper rather than one implementation per screen: the audit log already
 * had working pagination and nothing else did, and copying it nine times is
 * how nine screens end up disagreeing about what "page 2" means. Each table
 * still owns its own SQL, its filters and its access rules; all this owns is
 * the arithmetic and the controls.
 *
 * Only the rows for the current page are fetched. The total comes from a
 * separate COUNT over the same WHERE and the same bound values, so the number
 * in the heading always describes the set the rows came from.
 */
require_once __DIR__ . '/functions.php';

/** The sizes offered. Anything else is not a size. */
const PAGE_SIZES = [10, 20, 50, 100];
const PAGE_SIZE_DEFAULT = 10;

/**
 * The requested rows-per-page, validated.
 *
 * Read from the request, so it is never trusted: a value outside the list -
 * 7, 100000, "all", a quoted SQL fragment - becomes the default rather than
 * reaching the query. It is also bound as an integer below, so even a value
 * that passed here could not carry SQL with it.
 */
function page_size(): int
{
    $asked = (int)($_GET['per_page'] ?? 0);

    return in_array($asked, PAGE_SIZES, true) ? $asked : PAGE_SIZE_DEFAULT;
}

/**
 * Run a count and a page of rows, and return everything a table needs to
 * describe itself.
 *
 * $countSql and $rowSql are the module's own queries, already carrying its
 * WHERE clause. $rowSql must not carry its own LIMIT - that is added here with
 * bound integers.
 *
 * $orderBy is appended verbatim, so callers pass a literal they wrote, never
 * anything from the request. sort_clause() below is for the case where a
 * screen does let the reader choose.
 *
 * @return array{rows: array, total: int, page: int, pages: int, perPage: int,
 *               offset: int, from: int, to: int}
 */
function paginate(
    string $countSql,
    string $rowSql,
    array $params = [],
    string $orderBy = '',
    ?int $defaultPage = null
): array {
    $perPage = page_size();

    $countStmt = db()->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));

    /* A page beyond the end lands on the last one rather than showing an empty
       table. That is what keeps a table usable after the last row on page 4 is
       deleted, or after a filter narrows the set. */
    /* $defaultPage lets a screen open on the page holding something specific -
       the row being edited, say - while an explicit ?page in the request still
       wins, so the pager keeps working once you are there. */
    $page = isset($_GET['page'])
        ? max(1, (int)$_GET['page'])
        : max(1, $defaultPage ?? 1);
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $sql = $rowSql . ($orderBy !== '' ? ' ' . $orderBy : '') . ' LIMIT :pg_lim OFFSET :pg_off';
    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value);
    }
    // Bound as integers: LIMIT and OFFSET cannot be placeholders in every
    // driver configuration, and interpolating them is how a page number
    // becomes an injection point.
    $stmt->bindValue('pg_lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue('pg_off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'perPage' => $perPage,
        'offset' => $offset,
        'from' => $total ? $offset + 1 : 0,
        'to' => $total ? min($offset + $perPage, $total) : 0,
    ];
}

/**
 * An ORDER BY built from a request, safely.
 *
 * The column and the direction are looked up in an allowlist the caller
 * supplies, so what reaches the SQL is a string the screen wrote, never one
 * the reader did. A secondary key is appended to keep the order total: two
 * rows with the same sort value would otherwise be free to swap between pages,
 * which is how a row appears twice or not at all.
 */
function sort_clause(array $allowed, string $fallbackKey, string $tieBreaker): string
{
    $key = (string)($_GET['sort'] ?? '');
    if (!isset($allowed[$key])) {
        $key = $fallbackKey;
    }
    $dir = strtolower((string)($_GET['dir'] ?? '')) === 'asc' ? 'ASC' : 'DESC';

    return 'ORDER BY ' . $allowed[$key] . ' ' . $dir . ', ' . $tieBreaker;
}

/**
 * A URL for this screen with some query parameters changed.
 *
 * Everything else in the current query string is carried over, which is what
 * keeps a search, a filter and a sort alive while paging. Passing null for a
 * key drops it.
 */
function page_url(array $changes = []): string
{
    $qs = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($qs[$key]);
        } else {
            $qs[$key] = $value;
        }
    }
    // page=1 is the default; leaving it out keeps shared links tidy.
    if (($qs['page'] ?? null) === 1 || ($qs['page'] ?? null) === '1') {
        unset($qs['page']);
    }

    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');

    return $path . ($qs ? '?' . http_build_query($qs) : '');
}

/**
 * The page numbers worth showing: the first, the last, and a window around
 * the current one, with nulls where a gap was skipped.
 *
 * A hundred pages should not render a hundred buttons.
 */
function page_numbers(int $page, int $pages, int $window = 2): array
{
    if ($pages <= 7) {
        return range(1, $pages);
    }

    $show = [1, $pages];
    for ($i = $page - $window; $i <= $page + $window; $i++) {
        if ($i > 1 && $i < $pages) {
            $show[] = $i;
        }
    }
    $show = array_values(array_unique($show));
    sort($show);

    $out = [];
    $previous = 0;
    foreach ($show as $n) {
        if ($previous && $n - $previous > 1) {
            $out[] = null;          // a gap
        }
        $out[] = $n;
        $previous = $n;
    }

    return $out;
}
