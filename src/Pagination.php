<?php

namespace Timber;

/**
 * Class Pagination
 *
 * @api
 */
class Pagination
{
    public $current;

    public $total;

    public $pages;

    public $next;

    public $prev;

    /**
     * Pagination constructor.
     *
     * @api
     *
     * @param array           $prefs
     * @param \WP_Query|null  $wp_query
     */
    public function __construct($prefs = [], $wp_query = null)
    {
        $this->init($prefs, $wp_query);
    }

    /**
     * Get pagination.
     *
     * @api
     * @param array   $prefs
     * @return array mixed
     */
    public static function get_pagination($prefs = [])
    {
        $pagination = new self($prefs);
        $pagination = get_object_vars($pagination);
        return $pagination;
    }

    protected function init($prefs = [], $wp_query = null)
    {
        if (!$wp_query) {
            global $wp_query;
        }

        // use the current page from the provided query if available; else fall back to the global
        $paged = $wp_query->query_vars['paged'] ?? get_query_var('paged');

        global $wp_rewrite;
        $args = [];
        // calculate the total number of pages based on found posts and posts per page
        $ppp = $wp_query->query_vars['posts_per_page'] ?? 10;

        $args['total'] = ceil($wp_query->found_posts / $ppp);
        if ($wp_rewrite->using_permalinks()) {
            $url = explode('?', get_pagenum_link(0, false));
            if (isset($url[1])) {
                $query = [];
                wp_parse_str($url[1], $query);
                $args['add_args'] = $query;
            }
            $args['format'] = $wp_rewrite->pagination_base . '/%#%';
            $args['base'] = trailingslashit($url[0]) . '%_%';
        } else {
            $big = 999999999;
            $pagination_link = get_pagenum_link($big, false);
            $args['base'] = str_replace('paged=' . $big, '', $pagination_link);
            $args['format'] = '?paged=%#%';
        }

        $args['type'] = 'array';
        $args['current'] = max(1, $paged);
        $args['mid_size'] = max(9 - $args['current'], 3);
        if (is_int($prefs)) {
            $args['mid_size'] = $prefs - 2;
        } else {
            $args = array_merge($args, $prefs);
        }
        $this->current = $args['current'];
        $this->total = $args['total'];
        $this->pages = self::paginate_links($args);
        if ($this->total <= count($this->pages)) {
            // decrement current so that it matches up with the 0 based index used by the pages array
            $current = $this->current - 1;
        } else {
            // $data['current'] can't be used b/c there are more than 10 pages and we are condensing with dots
            foreach ($this->pages as $key => $page) {
                if (!empty($page['current'])) {
                    $current = $key;
                    break;
                }
            }
        }

        // set next and prev using pages array generated by paginate links
        if (isset($current) && isset($this->pages[$current + 1]) && isset($this->pages[$current + 1]['link'])) {
            $this->next = [
                'link' => $this->pages[$current + 1]['link'],
                'class' => 'page-numbers next',
            ];
        }
        if (isset($current) && isset($this->pages[$current - 1]) && isset($this->pages[$current - 1]['link'])) {
            $this->prev = [
                'link' => $this->pages[$current - 1]['link'],
                'class' => 'page-numbers prev',
            ];
        }
        if ($paged < 2) {
            $this->prev = '';
        }
        if ($this->total === (float) 0) {
            $this->next = '';
        }
    }

    /**
     *
     *
     * @param array  $args
     * @return array
     */
    public static function paginate_links($args = [])
    {
        $defaults = [
            'base' => '%_%',
            // http://example.com/all_posts.php%_% : %_% is replaced by format (below)
            'format' => '?page=%#%',
            // ?page=%#% : %#% is replaced by the page number
            'total' => 1,
            'current' => 0,
            'show_all' => false,
            'prev_next' => false,
            'prev_text' => __('&laquo; Previous'),
            'next_text' => __('Next &raquo;'),
            'start_size' => -1,
            'end_size' => 1,
            'mid_size' => 2,
            'type' => 'array',
            'add_args' => [],
            // array of query args to add
            'add_fragment' => '',
        ];
        $args = wp_parse_args($args, $defaults);

        $args = self::sanitize_args($args);

        // Who knows what else people pass in $args
        $args['total'] = intval((int) $args['total']);
        if ($args['total'] < 2) {
            return [];
        }
        $args['current'] = (int) $args['current'];
        $args['end_size'] = 0 <= (int) $args['end_size'] ? (int) $args['end_size'] : 1; // Out of bounds?  Make it the default.
        $args['start_size'] = 0 <= (int) $args['start_size'] ? (int) $args['start_size'] : $args['end_size']; // Default to end_size for backwards compat
        $args['mid_size'] = 0 <= (int) $args['mid_size'] ? (int) $args['mid_size'] : 2;
        $args['add_args'] = is_array($args['add_args']) ? $args['add_args'] : false;
        $page_links = [];
        $dots = true;
        for ($n = 1; $n <= $args['total']; $n++) {
            $n_display = number_format_i18n($n);
            if ($n == $args['current']) {
                $page_links[] = [
                    'class' => 'page-number page-numbers current',
                    'title' => $n_display,
                    'text' => $n_display,
                    'name' => $n_display,
                    'current' => true,
                ];
                $dots = true;
            } else {
                if (
                    $args['show_all']
                    || (
                        $n <= (int) $args['start_size']
                        || (
                            $args['current']
                            && $n >= (int) $args['current'] - (int) $args['mid_size']
                            && $n <= (int) $args['current'] + (int) $args['mid_size']
                        )
                        || $n > (int) $args['total'] - (int) $args['end_size']
                    )
                ) {
                    $link = str_replace('%_%', 1 == $n ? '' : $args['format'], $args['base']);
                    $link = str_replace('%#%', $n, $link);

                    // we first follow the user trailing slash configuration
                    $link = URLHelper::user_trailingslashit($link);

                    // then we add all required querystring parameters
                    if ($args['add_args']) {
                        $link = add_query_arg($args['add_args'], $link);
                    }

                    // last, we add fragment if needed
                    $link .= $args['add_fragment'];

                    $link = apply_filters('paginate_links', $link);

                    $page_links[] = [
                        'class' => 'page-number page-numbers',
                        'link' => esc_url($link),
                        'title' => $n_display,
                        'name' => $n_display,
                        'current' => $args['current'] == $n,
                    ];
                    $dots = true;
                } elseif ($dots && !$args['show_all']) {
                    $page_links[] = [
                        'class' => 'dots',
                        'title' => __('&hellip;'),
                    ];
                    $dots = false;
                }
            }
        }

        return $page_links;
    }

    protected static function sanitize_url_params($add_args)
    {
        foreach ($add_args as $key => $value) {
            $add_args[$key] = urlencode_deep($value);
        }
        return $add_args;
    }

    protected static function sanitize_args($args)
    {
        $format_args = [];

        $format = explode('?', str_replace('%_%', $args['format'], $args['base']));
        $format_query = isset($format[1]) ? $format[1] : '';

        wp_parse_str($format_query, $format_args);

        // Remove the format argument from the array of query arguments, to avoid overwriting custom format.
        foreach ($format_args as $format_arg => $format_arg_value) {
            unset($args['add_args'][urlencode_deep($format_arg)]);
        }

        $url_parts = explode('?', $args['base']);

        if (isset($url_parts[1])) {
            // Find the query args of the requested URL.
            $url_query_args = [];
            wp_parse_str($url_parts[1], $url_query_args);

            $args['add_args'] = array_merge($args['add_args'], urlencode_deep($url_query_args));
            $args['base'] = $url_parts[0] . '%_%';
        }

        if (isset($args['add_args'])) {
            $args['add_args'] = self::sanitize_url_params($args['add_args']);
        }
        return $args;
    }
}
