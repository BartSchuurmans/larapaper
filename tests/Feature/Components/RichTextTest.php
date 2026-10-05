<?php

it('keeps allowed markup', function (): void {
    $html = '<p>A <strong>bold</strong> and <em>italic</em> word</p><ul><li>item</li></ul>';

    $view = $this->blade('<x-rich-text :html="$html" />', ['html' => $html]);

    $view->assertSee($html, false);
});

it('strips scripts and event handlers', function (): void {
    $html = '<p onclick="alert(1)">Hello</p><script>alert("xss")</script>';

    $view = $this->blade('<x-rich-text :html="$html" />', ['html' => $html]);

    $view->assertSee('<p>Hello</p>', false)
        ->assertDontSee('onclick', false)
        ->assertDontSee('<script', false)
        ->assertDontSee('alert(', false);
});

it('merges caller classes', function (): void {
    $view = $this->blade('<x-rich-text class="mt-2" html="Hi" />');

    $view->assertSee('class="rich-text mt-2"', false);
});

it('opens outgoing links in a new tab with rel noopener', function (): void {
    $html = '<a href="https://example.com" target="_top">external</a> <a href="/plugins">internal</a>';

    $view = $this->blade('<x-rich-text :html="$html" />', ['html' => $html]);

    $view->assertSee('<a href="https://example.com" target="_blank" rel="noreferrer noopener">external</a>', false)
        ->assertSee('<a href="/plugins">internal</a>', false);
});
