<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * URI filter of HTML Purifier: embedded resources – the images of the
 * content – may only come from the website's own image store, i.e. an
 * address `/media/<hash>.jpg|png` (R42.4). Links are not affected.
 *
 * Images of other servers would let every visitor's browser contact those
 * servers, and the page CSP does not allow them anyway.
 */
final class LocalImageUriFilter extends \HTMLPurifier_URIFilter
{
    /** @var string */
    public $name = 'LocalImages';

    /**
     * @param \HTMLPurifier_URI     $uri
     * @param \HTMLPurifier_Config  $config
     * @param \HTMLPurifier_Context $context
     */
    public function filter(&$uri, $config, $context): bool
    {
        if (! $context->get('EmbeddedURI', true)) {
            return true;
        }

        return $uri->scheme === null
            && $uri->host === null
            && $uri->query === null
            && $uri->fragment === null
            && preg_match('#^/media/[0-9a-f]{64}\.(jpg|png)$#', (string) $uri->path) === 1;
    }
}
