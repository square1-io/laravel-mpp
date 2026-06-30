<?php

namespace Square1\Mpp\Tests;

/**
 * Variant of the base TestCase with attribute auto-enforcement enabled, so we
 * can assert the provider registers the EnforcePaymentAttributes middleware on
 * the configured route groups.
 */
abstract class AttributesEnabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mpp.attributes.enabled', true);
    }
}
