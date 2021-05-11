<?php

namespace DoubleThreeDigital\Runway;

use Facades\Statamic\View\Cascade;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Str;
use Statamic\Events\ResponseCreated;
use Statamic\Facades\Site;
use Statamic\Statamic;
use Statamic\View\View;

class ResourceResponse implements Responsable
{
    protected $data;
    protected $request;
    protected $headers = [];
    protected $with = [];

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function toResponse($request)
    {
        $this->request = $request;

        $this
            ->adjustResponseType()
            ->addContentHeaders()
            ->addViewPaths();

        $response = response()
            ->make($this->contents())
            ->withHeaders($this->headers);

        ResponseCreated::dispatch($response);

        return $response;
    }

    protected function addViewPaths()
    {
        $finder = view()->getFinder();
        $amp = Statamic::isAmpRequest();

        $site = method_exists($this->data, 'site')
            ? $this->data->site()->handle()
            : Site::current()->handle();

        $paths = collect($finder->getPaths())->flatMap(function ($path) use ($site, $amp) {
            return [
                $amp ? $path.'/'.$site.'/amp' : null,
                $path.'/'.$site,
                $amp ? $path.'/amp' : null,
                $path,
            ];
        })->filter()->values()->all();

        $finder->setPaths($paths);

        return $this;
    }

    protected function contents()
    {
        $contents = (new View)
            ->template($this->data->template())
            ->layout($this->data->layout())
            ->with($this->with)
            ->cascadeContent($this->data)
            ->render();

        if ($this->isLivePreview()) {
            $contents = $this->versionJavascriptModules($contents);
        }

        return $contents;
    }

    protected function cascade()
    {
        return Cascade::instance()->withContent($this->data)->hydrate();
    }

    protected function adjustResponseType()
    {
        // $contentType = $this->data->get('content_type', 'html');

        // if ($contentType !== 'html') {
        //     $this->headers['Content-Type'] = self::contentType($contentType);
        // }

        return $this;
    }

    protected function addContentHeaders()
    {
        // foreach ($this->data->get('headers', []) as $header => $value) {
        //     $this->headers[$header] = $value;
        // }

        return $this;
    }

    public function with($data)
    {
        $this->with = $data;

        return $this;
    }

    protected function isLivePreview()
    {
        return $this->request->headers->get('X-Statamic-Live-Preview');
    }

    protected function versionJavascriptModules($contents)
    {
        return preg_replace_callback('~<script[^>]*type=("|\')module\1[^>]*>~i', function ($scriptMatches) {
            return preg_replace_callback('~src=("|\')(.*?)\1~i', function ($matches) {
                $quote = $matches[1];
                $url = $matches[2];

                $parameter = 't='.(microtime(true) * 10000);

                if (Str::contains($url, '?')) {
                    $url = str_replace('?', "?$parameter&", $url);
                } else {
                    $url .= "?$parameter";
                }

                return 'src='.$quote.$url.$quote;
            }, $scriptMatches[0]);
        }, $contents);
    }

    public static function contentType($type)
    {
        switch ($type) {
            case 'html':
                return 'text/html; charset=UTF-8';
            case 'xml':
                return 'text/xml';
            case 'rss':
                return 'application/rss+xml';
            case 'atom':
                return 'application/atom+xml; charset=UTF-8';
            case 'json':
                return 'application/json';
            case 'text':
                return 'text/plain';
            default:
                return $type;
        }
    }
}