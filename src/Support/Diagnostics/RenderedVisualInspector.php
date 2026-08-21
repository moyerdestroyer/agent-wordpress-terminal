<?php

/**
 * Browser-rendered same-site visual and computed-style inspection.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

final class RenderedVisualInspector {
    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function inspect(string $url, string $selector = '', bool $include_screenshot = true): array|\WP_Error {
        if (!new SameSiteUrlPolicy()->is_allowed($url)) {
            return new \WP_Error(
                'awpt_visual_url_not_allowed',
                __('Rendered inspection is limited to this WordPress site.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $chrome = $this->chrome_binary();

        if (null === $chrome || !function_exists('shell_exec') || !is_callable('shell_exec')) {
            return $this->static_fallback($url, $selector, 'headless_browser_unavailable');
        }

        $workspace = $this->workspace();

        if (is_wp_error($workspace)) {
            return $this->static_fallback($url, $selector, 'capture_workspace_unavailable');
        }

        $authorization = new RenderedPreviewAuthenticator()->issue($url);

        if (is_wp_error($authorization)) {
            return $this->static_fallback($url, $selector, 'capture_authentication_unavailable');
        }

        $wrapper_path = $workspace['path'] . '/inspect-' . wp_generate_password(20, false, false) . '.html';
        $screenshot_path = $workspace['path'] . '/capture-' . wp_generate_password(20, false, false) . '.png';
        $wrapper_url = $workspace['url'] . '/' . basename($wrapper_path);
        $html = $this->wrapper_html($authorization['url'], $selector);

        if (false === file_put_contents($wrapper_path, $html, LOCK_EX)) {
            new RenderedPreviewAuthenticator()->revoke($authorization['key']);

            return $this->static_fallback($url, $selector, 'capture_wrapper_unavailable');
        }

        $command = sprintf(
            '%s --headless=new --no-sandbox --disable-gpu --hide-scrollbars --ignore-certificate-errors '
            . '--window-size=1440,1200 '
            . '--virtual-time-budget=7000 --dump-dom %s %s 2>/dev/null',
            escapeshellcmd($chrome),
            $include_screenshot ? '--screenshot=' . escapeshellarg($screenshot_path) : '',
            escapeshellarg($wrapper_url),
        );
        $dom = shell_exec($command);
        $result = $this->result_from_dom(is_string($dom) ? $dom : '');
        $image_data = $include_screenshot ? $this->image_data($screenshot_path) : '';
        new RenderedPreviewAuthenticator()->revoke($authorization['key']);
        unlink($wrapper_path);

        if (is_file($screenshot_path)) {
            unlink($screenshot_path);
        }

        if (null === $result) {
            return $this->static_fallback($url, $selector, 'browser_capture_failed');
        }

        return [
            'url' => $url,
            'selector' => $selector,
            'rendered' => true,
            'engine' => basename($chrome),
            'viewport' => ['width' => 1440, 'height' => 1200],
            'document' => is_array($result['document'] ?? null) ? $result['document'] : [],
            'elements' => is_array($result['elements'] ?? null) ? $result['elements'] : [],
            'main_heading_outline' => is_array($result['main_heading_outline'] ?? null)
                ? $result['main_heading_outline']
                : [],
            'main_h1_count' => (int) ($result['main_h1_count'] ?? 0),
            'matched_count' => (int) ($result['matched_count'] ?? 0),
            'screenshot_data' => $image_data,
            'has_screenshot' => '' !== $image_data,
            'warning' => '',
        ];
    }

    /**
     * @return array{path: string, url: string}|\WP_Error
     */
    private function workspace(): array|\WP_Error {
        $uploads = wp_upload_dir();

        if ($uploads['error']) {
            return new \WP_Error('awpt_visual_upload_dir', __(
                'Uploads directory is unavailable.',
                'agent-wordpress-terminal',
            ));
        }

        $path = trailingslashit($uploads['basedir']) . 'awpt-render-inspection';
        $url = trailingslashit($uploads['baseurl']) . 'awpt-render-inspection';

        if (!wp_mkdir_p($path)) {
            return new \WP_Error('awpt_visual_workspace', __(
                'Could not create the render workspace.',
                'agent-wordpress-terminal',
            ));
        }

        return ['path' => $path, 'url' => $url];
    }

    private function wrapper_html(string $url, string $selector): string {
        $encoded_url = wp_json_encode($url);
        $encoded_selector = wp_json_encode($selector);
        $target = false === $encoded_url ? '""' : $encoded_url;
        $requested = false === $encoded_selector ? '""' : $encoded_selector;

        return (
            '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'html,body{margin:0;width:100%;height:100%;overflow:hidden}iframe{border:0;width:100%;height:100%}'
            . '#awpt-result{display:none}</style></head><body><iframe id="target"></iframe><pre id="awpt-result"></pre>'
            . '<script>(()=>{const target='
            . $target
            . ';const requested='
            . $requested
            . ';'
            . 'const frame=document.getElementById("target");const output=document.getElementById("awpt-result");'
            . 'const finish=(value)=>{output.textContent=btoa(unescape(encodeURIComponent(JSON.stringify(value))));};'
            . 'frame.onload=()=>setTimeout(()=>{try{const doc=frame.contentDocument;const win=frame.contentWindow;'
            . 'const selectors=requested?[requested]:["main",".entry-content","[class*=icon]","svg","img","button","a","h1","h2","h3"];'
            . 'const found=[];const seen=new Set();for(const query of selectors){let nodes=[];try{nodes=[...doc.querySelectorAll(query)]}catch(e){}'
            . 'for(const element of nodes){if(seen.has(element)||found.length>=32)continue;seen.add(element);'
            . 'const rect=element.getBoundingClientRect();const style=win.getComputedStyle(element);'
            . 'found.push({selector:query,tag:element.tagName.toLowerCase(),id:element.id||"",'
            . 'classes:[...element.classList].slice(0,12),text:(element.getAttribute("aria-label")||element.textContent||"").trim().slice(0,180),'
            . 'rect:{x:Math.round(rect.x),y:Math.round(rect.y),width:Math.round(rect.width),height:Math.round(rect.height)},'
            . 'computed:{display:style.display,position:style.position,width:style.width,height:style.height,fontSize:style.fontSize,'
            . 'lineHeight:style.lineHeight,padding:style.padding,margin:style.margin,color:style.color,backgroundColor:style.backgroundColor,'
            . 'transform:style.transform,overflow:style.overflow,visibility:style.visibility,opacity:style.opacity}});}}'
            . 'const main=doc.querySelector("main")||doc.body;const headings=[...main.querySelectorAll("h1,h2,h3,h4,h5,h6")]'
            . '.filter(element=>{const style=win.getComputedStyle(element);const rect=element.getBoundingClientRect();'
            . 'return style.display!=="none"&&style.visibility!=="hidden"&&Number(style.opacity)!==0&&rect.width>0&&rect.height>0})'
            . '.slice(0,64).map(element=>({level:Number(element.tagName.slice(1)),text:(element.textContent||"").trim().slice(0,180)}));'
            . 'finish({document:{title:doc.title,url:frame.contentWindow.location.href,scrollWidth:doc.documentElement.scrollWidth,'
            . 'scrollHeight:doc.documentElement.scrollHeight},main_heading_outline:headings,'
            . 'main_h1_count:headings.filter(heading=>heading.level===1).length,matched_count:found.length,elements:found});'
            . '}catch(error){finish({error:String(error),matched_count:0,elements:[]});}},1200);'
            . 'frame.src=target;setTimeout(()=>{if(!output.textContent)finish({error:"capture timeout",matched_count:0,elements:[]})},6500);'
            . '})()</script></body></html>'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function result_from_dom(string $dom): ?array {
        $matches = [];

        if (!preg_match('~<pre id="awpt-result">([^<]+)</pre>~', $dom, $matches)) {
            return null;
        }

        $decoded = base64_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);
        $result = is_string($decoded) ? json_decode($decoded, true) : null;

        /** @var array<string, mixed>|null $result */
        return is_array($result) ? $result : null;
    }

    private function image_data(string $path): string {
        if (!is_readable($path)) {
            return '';
        }

        $bytes = filesize($path);

        if (false === $bytes || $bytes <= 0 || $bytes > 3_000_000) {
            return '';
        }

        $content = file_get_contents($path);

        return is_string($content) ? 'data:image/png;base64,' . base64_encode($content) : '';
    }

    private function chrome_binary(): ?string {
        /** @var mixed $filtered */
        $filtered = apply_filters('awpt_headless_browser_binary', '');

        if (is_string($filtered) && '' !== $filtered && is_executable($filtered)) {
            return $filtered;
        }

        foreach ([
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/local/bin/google-chrome',
        ] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    private function static_fallback(string $url, string $selector, string $warning): array|\WP_Error {
        $authorization = new RenderedPreviewAuthenticator()->issue($url);
        $target_url = is_wp_error($authorization) ? $url : $authorization['url'];
        $static = new FrontendInspector()->inspect($target_url, $selector);

        if (!is_wp_error($authorization)) {
            new RenderedPreviewAuthenticator()->revoke($authorization['key']);
        }

        if (is_wp_error($static)) {
            return $static;
        }

        return [
            ...$static,
            'url' => $url,
            'rendered' => false,
            'engine' => 'static_html',
            'elements' => [],
            'matched_count' => 0,
            'screenshot_data' => '',
            'has_screenshot' => false,
            'warning' => $warning,
        ];
    }
}
