<?php

/**
 * The slice of FacetWP 4.5 that Integrations\FacetWP calls, with the same
 * shapes: scanned by phpstan, and loaded by FacetWPTest as a fake whose
 * render() output the test sets.
 */
class FacetWP_Display
{
    /** @var array<string, string> */
    public $active_facets = [];

    /** @var array<string, bool> */
    public $active_extras = [];
}

class FacetWP_Request
{
    /** @var array<string, mixed> */
    public $url_vars = [];

    /**
     * FacetWP's real check also takes the main query of an archive; the fake
     * only honours the `facetwp` flag a listing block sets.
     *
     * @param  WP_Query  $query
     * @return bool
     */
    public function is_main_query($query)
    {
        return $query->get('facetwp') === true;
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public function process_preload_overrides($items)
    {
        return ['facets' => array_values($items['facets'])];
    }

    /**
     * @param  string|false  $template_name
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function process_preload_data($template_name = false, $overrides = [])
    {
        return $overrides;
    }
}

class FacetWP_Renderer
{
    /** @var array<string, mixed> */
    public $output = [];

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function render($params)
    {
        return $this->output;
    }
}

class FacetWP_Helper
{
    /** @var array<string, array<string, mixed>> */
    public $facets = [];

    /**
     * @return mixed
     */
    public function get_setting(string $name, mixed $default = '')
    {
        return $name === 'prefix' ? '_' : $default;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function get_facet_by_name(string $name)
    {
        return $this->facets[$name] ?? false;
    }
}

class FacetWP
{
    public FacetWP_Display $display;

    public FacetWP_Request $request;

    public FacetWP_Renderer $facet;

    public FacetWP_Helper $helper;

    public function __construct()
    {
        $this->display = new FacetWP_Display;
        $this->request = new FacetWP_Request;
        $this->facet = new FacetWP_Renderer;
        $this->helper = new FacetWP_Helper;
    }
}

function FWP(): FacetWP
{
    static $fwp;

    return $fwp ??= new FacetWP;
}
