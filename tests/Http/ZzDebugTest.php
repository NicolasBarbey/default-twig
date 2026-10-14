<?php
declare(strict_types=1);
namespace BackOfficeDefaultTwigBundle\Tests\Http;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\CurrencyQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
final class ZzDebugTest extends WebIntegrationTestCase
{
    public function testDebug(): void
    {
        $injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($injector);
        $factory = new FixtureFactory($this->getPropelConnection());
        $admin = $factory->admin(); $admin->eraseCredentials(); $injector->setAdmin($admin);
        $parent = $factory->category();
        $category = $factory->category(['parent' => $parent->getId()]);
        $this->client->request('GET', '/admin/categories/update?category_id='.$category->getId());
        fwrite(STDERR, $this->client->getResponse()->getStatusCode()."\n".substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 1500)."\n");
        $product = $factory->product($factory->category(), $factory->taxRule(), CurrencyQuery::create()->findOneByByDefault(1));
        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());
        fwrite(STDERR, 'href='.$crawler->filter('[data-testid="product-edit-cancel"]')->attr('href')."\n");
        $this->client->submit($crawler->filter('[data-testid="product-edit-save-close"]')->form());
        $this->client->followRedirect();
        fwrite(STDERR, implode("\n", $this->client->getCrawler()->filter('.alert, .invalid-feedback, .toast-body')->each(fn ($n) => trim($n->text())))."\n");
        $injector->clear();
    }
}
