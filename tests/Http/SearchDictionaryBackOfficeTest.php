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

use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\SearchSynonym;
use Thelia\Model\SearchSynonymQuery;
use Thelia\Model\SearchTermRedirect;
use Thelia\Model\SearchTermRedirectQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

final class SearchDictionaryBackOfficeTest extends WebIntegrationTestCase
{
    private const SYNONYMS_URL = '/admin/configuration/search-synonyms';
    private const REDIRECTS_URL = '/admin/configuration/search-redirects';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private int $languageId;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        Propel::disableInstancePooling();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->factory = new FixtureFactory($this->getPropelConnection());
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $this->languageId = (int) LangQuery::create()->findOneByByDefault(1)?->getId();
    }

    protected function tearDown(): void
    {
        Propel::enableInstancePooling();

        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheTwoListsShowTheEntriesOfTheFilteredLanguage(): void
    {
        (new SearchSynonym())->setLangId($this->languageId)->setTerm('b239sweater')->setSynonyms("jumper\npullover")->setBidirectional(1)->setActive(1)->save($this->getPropelConnection());
        (new SearchTermRedirect())->setLangId($this->languageId)->setTerm('b239promo')->setTargetType('url')->setUrl('/b239-offers')->setActive(1)->save($this->getPropelConnection());

        $this->assertPageRenders(self::SYNONYMS_URL.'?language_id='.$this->languageId);
        $page = $this->client->getCrawler()->filter('[data-testid="search-synonyms-page"]')->text();
        self::assertStringContainsString('b239sweater', $page);
        self::assertStringContainsString('jumper, pullover', $page);

        $this->assertPageRenders(self::REDIRECTS_URL.'?language_id='.$this->languageId);
        $page = $this->client->getCrawler()->filter('[data-testid="search-redirects-page"]')->text();
        self::assertStringContainsString('b239promo', $page);
        self::assertStringContainsString('/b239-offers', $page);
    }

    public function testAnAdministratorWithoutTheConfigurationRightIsRefused(): void
    {
        $nobody = $this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]);
        $nobody->eraseCredentials();
        $this->injector->setAdmin($nobody);

        $this->client->request('GET', self::SYNONYMS_URL);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', self::REDIRECTS_URL);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::SYNONYMS_URL.'/create', ['thelia_search_synonym_create' => [
            'lang_id' => (string) $this->languageId,
            'term' => 'b239denied',
            'synonyms' => 'refused',
        ]]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull(SearchSynonymQuery::create()->findOneByTerm('b239denied'));
    }

    public function testTheMerchantCreatesASynonymByPost(): void
    {
        $token = $this->formToken(self::SYNONYMS_URL.'?language_id='.$this->languageId, 'thelia_search_synonym_create');

        $this->client->request('POST', self::SYNONYMS_URL.'/create', ['thelia_search_synonym_create' => [
            'lang_id' => (string) $this->languageId,
            'term' => 'b239hoodie',
            'synonyms' => "sweatshirt\r\nhooded top\r\n",
            'bidirectional' => '1',
            'active' => '1',
            '_token' => $token,
        ]]);

        $synonym = SearchSynonymQuery::create()->findOneByTerm('b239hoodie');
        self::assertNotNull($synonym, 'The synonym is created.');
        self::assertSame(['sweatshirt', 'hooded top'], explode("\n", (string) $synonym->getSynonyms()));
        self::assertSame(1, $synonym->getBidirectional());
        self::assertSame($this->languageId, $synonym->getLangId());
        self::assertTrue($this->client->getResponse()->isRedirect(self::SYNONYMS_URL.'?language_id='.$this->languageId));
    }

    public function testASynonymPostedWithoutTheFormTokenIsNotCreated(): void
    {
        $this->client->request('POST', self::SYNONYMS_URL.'/create', ['thelia_search_synonym_create' => [
            'lang_id' => (string) $this->languageId,
            'term' => 'b239notoken',
            'synonyms' => 'refused',
            'active' => '1',
        ]]);

        self::assertNull(SearchSynonymQuery::create()->findOneByTerm('b239notoken'));
    }

    public function testTheMerchantDeletesASynonym(): void
    {
        $synonym = (new SearchSynonym())->setLangId($this->languageId)->setTerm('b239delete')->setSynonyms('gone')->setActive(1);
        $synonym->save($this->getPropelConnection());

        $this->client->request('POST', self::SYNONYMS_URL.'/delete', [
            '_token' => $this->backOfficeToken(self::SYNONYMS_URL.'?language_id='.$this->languageId),
            'search_synonym_id' => (string) $synonym->getId(),
            'language_id' => (string) $this->languageId,
        ]);

        self::assertNull(SearchSynonymQuery::create()->findOneByTerm('b239delete'));
        self::assertTrue($this->client->getResponse()->isRedirect(self::SYNONYMS_URL.'?language_id='.$this->languageId));
    }

    public function testAnAdministratorWhoCannotUpdateCannotImport(): void
    {
        $creator = $this->factory->restrictedAdmin([AdminResources::CONFIG => [AccessManager::VIEW, AccessManager::CREATE]]);
        $creator->eraseCredentials();
        $this->injector->setAdmin($creator);

        $this->client->request('POST', self::SYNONYMS_URL.'/import', ['language_id' => (string) $this->languageId]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheLanguageSwitcherGoesBackToTheFirstPage(): void
    {
        $other = LangQuery::create()->filterByByDefault(0)->findOne();
        self::assertNotNull($other, 'The shop has a language other than its default one.');

        $crawler = $this->client->request('GET', self::SYNONYMS_URL.'?language_id='.$this->languageId.'&page=4');
        $link = (string) $crawler->filter('[data-testid="bo-language-switcher-'.$other->getCode().'"]')->attr('href');

        self::assertStringContainsString('language_id='.$other->getId(), $link);
        self::assertStringNotContainsString('page=', $link);
    }

    public function testARedirectionToAnExternalAddressIsRefusedAndTheReasonIsShown(): void
    {
        $token = $this->formToken(self::REDIRECTS_URL.'?language_id='.$this->languageId, 'thelia_search_redirect_create');

        $this->client->request('POST', self::REDIRECTS_URL.'/create', ['thelia_search_redirect_create' => [
            'lang_id' => (string) $this->languageId,
            'term' => 'b239evil',
            'target_type' => 'url',
            'url' => 'https://evil.example/',
            'active' => '1',
            '_token' => $token,
        ]]);

        self::assertNull(SearchTermRedirectQuery::create()->findOneByTerm('b239evil'), 'Nothing is created.');
        self::assertTrue($this->client->getResponse()->isRedirect(self::REDIRECTS_URL.'?language_id='.$this->languageId));

        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-testid="bo-flash-danger"]'), 'The refusal is shown as an error.');
        self::assertStringContainsString('path of the shop', $crawler->filter('[data-testid="bo-flash-danger"]')->text());
    }

    public function testTheImportFormsKeepTheFilteredLanguage(): void
    {
        $other = LangQuery::create()->filterByByDefault(0)->findOne();
        self::assertNotNull($other, 'The shop has a language other than its default one.');

        foreach (['search-synonym-import' => self::SYNONYMS_URL, 'search-redirect-import' => self::REDIRECTS_URL] as $testid => $url) {
            $crawler = $this->client->request('GET', $url.'?language_id='.$other->getId());

            self::assertSame((string) $other->getId(), $crawler->filter('[data-testid="'.$testid.'-form"] input[name="language_id"]')->attr('value'), $testid);
        }
    }

    public function testACsvFileOfSynonymsIsImported(): void
    {
        $page = (string) $this->client->request('GET', self::SYNONYMS_URL.'?language_id='.$this->languageId)->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $page, $matches), 'The page renders the back-office token.');

        $path = tempnam(sys_get_temp_dir(), 'b239');
        self::assertIsString($path);
        $locale = (string) LangQuery::create()->findPk($this->languageId)?->getLocale();
        file_put_contents($path, "locale;term;synonyms;bidirectional\n".$locale.";b239import;alpha|beta;1\n".$locale.";b239import2;gamma;0\n");

        try {
            $this->client->request('POST', self::SYNONYMS_URL.'/import', ['_token' => $matches[1], 'language_id' => (string) $this->languageId], [
                'file' => new UploadedFile($path, 'synonyms.csv', 'text/csv', null, true),
            ]);
        } finally {
            @unlink($path);
        }

        self::assertTrue($this->client->getResponse()->isRedirect(self::SYNONYMS_URL.'?language_id='.$this->languageId));
        $imported = SearchSynonymQuery::create()->findOneByTerm('b239import');
        self::assertNotNull($imported, 'The first line is imported.');
        self::assertSame("alpha\nbeta", $imported->getSynonyms());
        self::assertNotNull(SearchSynonymQuery::create()->findOneByTerm('b239import2'), 'The second line is imported.');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('2 created', $crawler->filter('[data-testid="bo-flash-success"]')->text());
    }

    private function backOfficeToken(string $page): string
    {
        $html = (string) $this->client->request('GET', $page)->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
    }

    private function formToken(string $page, string $formName): string
    {
        $crawler = $this->client->request('GET', $page);
        $token = $crawler->filter(\sprintf('input[name="%s[_token]"]', $formName));
        self::assertGreaterThan(0, $token->count(), \sprintf('The page renders the %s form.', $formName));

        return (string) $token->attr('value');
    }
}
