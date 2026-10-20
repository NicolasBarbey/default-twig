<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The product creation modal follows the edit language of the page it is opened
 * from: the locale the new product is saved in, and the language of the category titles.
 */
final class ProductCreationEditLanguageTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheModalCreatesTheProductInTheEditLanguage(): void
    {
        $defaultLang = Lang::getDefaultLanguage();
        $otherLang = LangQuery::create()->filterByByDefault(0)->filterByActive(1)->orderByLocale()->findOne();
        self::assertNotNull($otherLang, 'The test shop has a single active language.');

        $category = $this->factory->category();
        $category->setLocale($defaultLang->getLocale())->setTitle('Default title')->save($this->getPropelConnection());
        $category->setLocale($otherLang->getLocale())->setTitle('Other title')->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/products?edit_language_id='.$otherLang->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            $otherLang->getLocale(),
            $crawler->filter('#product-create-modal input[name$="[locale]"]')->attr('value'),
            'The product is created in the language being edited.',
        );
        self::assertStringContainsString(
            'Other title',
            $crawler->filter('#product-create-modal select[name$="[default_category]"]')->text(),
            'The categories are listed in the language being edited.',
        );

        $crawler = $this->client->request('GET', '/admin/products?edit_language_id='.$defaultLang->getId());

        self::assertSame($defaultLang->getLocale(), $crawler->filter('#product-create-modal input[name$="[locale]"]')->attr('value'));
        self::assertStringContainsString('Default title', $crawler->filter('#product-create-modal select[name$="[default_category]"]')->text());
    }
}
