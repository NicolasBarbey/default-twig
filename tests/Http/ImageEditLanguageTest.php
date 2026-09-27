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

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\File\FileCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\ProductImage;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The image edit screen says when the language being edited has no file of its own and
 * shows the one of the default language.
 */
final class ImageEditLanguageTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    /** @var list<string> */
    private array $files = [];

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
        ConfigQuery::write('default_lang_without_translation', (string) Lang::REPLACE_BY_DEFAULT_LANGUAGE);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        foreach ($this->files as $file) {
            @unlink($file);
        }

        ConfigQuery::resetCache();
        parent::tearDown();
    }

    public function testALanguageWithoutItsOwnFileIsToldItShowsTheDefaultOne(): void
    {
        $defaultLang = Lang::getDefaultLanguage();
        $otherLang = LangQuery::create()->filterByByDefault(0)->filterByActive(1)->orderByLocale()->findOne();
        self::assertNotNull($otherLang, 'The test shop has a single active language.');

        $image = $this->createImage($defaultLang->getLocale());
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $url = '/admin/image/type/product/'.$image->getId().'/update?edit_language_id=';

        $crawler = $this->client->request('GET', $url.$otherLang->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="bo-image-inherited-file"]'));
        self::assertCount(1, $crawler->filter('[data-testid="bo-image-preview"]'), 'The inherited file is previewed.');

        $crawler = $this->client->request('GET', $url.$defaultLang->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="bo-image-inherited-file"]'));
    }

    private function createImage(string $locale): ProductImage
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());

        $path = sys_get_temp_dir().\DIRECTORY_SEPARATOR.uniqid('thelia_test_img_').'.png';
        imagepng(imagecreatetruecolor(1, 1), $path);

        $model = new ProductImage();
        $model->setProductId($product->getId())->setVisible(1)->setLocale($locale)->setTitle('Image');

        $event = new FileCreateOrUpdateEvent($product->getId());
        $event
            ->setModel($model)
            ->setUploadedFile(new UploadedFile($path, 'default.png', 'image/png', null, true))
            ->setParentName('Product');

        $this->getService(EventDispatcherInterface::class)->dispatch($event, TheliaEvents::IMAGE_SAVE);

        $this->files[] = $model->getUploadDir().\DIRECTORY_SEPARATOR.$model->getOwnFile();

        return $model;
    }
}
