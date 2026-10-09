<?php

declare(strict_types=1);

namespace viesrood\igotissues\web\twig;

use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;
use viesrood\igotissues\web\Widget;

/**
 * `{{ igotissues_widget() }}`: the report button, for templates that do not
 * call endBody(). Outputs nothing for a visitor who may not report.
 */
class IGotIssuesExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('igotissues_widget', static fn(): Markup => new Markup(Widget::html(), 'UTF-8')),
        ];
    }
}
