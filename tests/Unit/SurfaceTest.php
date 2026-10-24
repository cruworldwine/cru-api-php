<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Client;
use CruWorldWine\Models;
use CruWorldWine\Page;
use CruWorldWine\Write;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every endpoint, written out by hand, so a generator change that moves one is caught.
 */
final class SurfaceTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(Client): mixed, string, string, mixed, string}>
     */
    public static function endpoints(): iterable
    {
        $page = ['limit' => 20, 'offset' => 0, 'has_more' => false];
        $lwin = self::LWIN;

        yield 'ping' => [fn (Client $c) => $c->ping(), 'GET', '/ping', ['pong' => true], Models\Ping::class];
        yield 'warehouses.list' => [fn (Client $c) => $c->warehouses->list(), 'GET', '/warehouses', ['warehouses' => [[]]], Models\Warehouse::class];
        yield 'warehouses.get' => [fn (Client $c) => $c->warehouses->get('UK_DRAK'), 'GET', '/warehouses/UK_DRAK', ['warehouse' => []], Models\Warehouse::class];
        yield 'inbondWarehouses.list' => [fn (Client $c) => $c->inbondWarehouses->list(), 'GET', '/warehouses/inbond', ['inbond_warehouses' => [[]]], Models\InbondWarehouse::class];
        yield 'inbondWarehouses.suggest' => [fn (Client $c) => $c->inbondWarehouses->suggest(['name' => 'Example Bond', 'country' => 'GB']), 'POST', '/warehouses/inbond', ['action' => 'inbond_warehouse.suggest'], Models\InbondWarehouseResult::class];
        yield 'inbondWarehouses.get' => [fn (Client $c) => $c->inbondWarehouses->get(12), 'GET', '/warehouses/inbond/12', ['inbond_warehouse' => []], Models\InbondWarehouse::class];
        yield 'wines.search' => [fn (Client $c) => $c->wines->search(['q' => 'Exemple']), 'GET', '/wines', ['wines' => [[]], '@page' => $page], Models\Wine::class];
        yield 'wines.get' => [fn (Client $c) => $c->wines->get($lwin), 'GET', '/wines/' . $lwin, ['wine' => []], Models\WineDetail::class];
        yield 'market.list' => [fn (Client $c) => $c->market->list(), 'GET', '/market', ['markets' => [[]], '@page' => $page], Models\MarketWine::class];
        yield 'market.getWine' => [fn (Client $c) => $c->market->getWine($lwin), 'GET', '/market/wines/' . $lwin, ['market' => []], Models\MarketWine::class];
        yield 'market.listBids' => [fn (Client $c) => $c->market->listBids(), 'GET', '/market/bids', ['markets' => [[]], '@page' => $page], Models\MarketWine::class];
        yield 'market.getBid' => [fn (Client $c) => $c->market->getBid('BID-x'), 'GET', '/market/bids/BID-x', ['bid' => []], Models\MarketBidDetail::class];
        yield 'market.getWineBids' => [fn (Client $c) => $c->market->getWineBids($lwin), 'GET', '/market/wines/' . $lwin . '/bids', ['market' => []], Models\MarketWine::class];
        yield 'market.listOffers' => [fn (Client $c) => $c->market->listOffers(), 'GET', '/market/offers', ['markets' => [[]], '@page' => $page], Models\MarketWine::class];
        yield 'market.getOffer' => [fn (Client $c) => $c->market->getOffer('OFFER-x'), 'GET', '/market/offers/OFFER-x', ['offer' => []], Models\MarketOfferDetail::class];
        yield 'market.getWineOffers' => [fn (Client $c) => $c->market->getWineOffers($lwin), 'GET', '/market/wines/' . $lwin . '/offers', ['market' => []], Models\MarketWine::class];
        yield 'market.buyOffer' => [fn (Client $c) => $c->market->buyOffer('OFFER-x', ['quantity' => 1]), 'POST', '/market/offers/OFFER-x/buy', ['action' => 'bid.buy'], Models\BuyResult::class];
        yield 'market.sellToBid' => [fn (Client $c) => $c->market->sellToBid('BID-x', ['stock_type' => 'cru', 'quantity' => 1]), 'POST', '/market/bids/BID-x/sell', ['action' => 'offer.sell'], Models\SellResult::class];
        yield 'photos.get' => [fn (Client $c) => $c->photos->get('PHOTO-x'), 'GET', '/photos/PHOTO-x', '@binary', 'string'];
        yield 'bids.list' => [fn (Client $c) => $c->bids->list(), 'GET', '/bids', ['bids' => [[]], '@page' => $page], Models\OwnBid::class];
        yield 'bids.place' => [fn (Client $c) => $c->bids->place(['lwin18' => $lwin, 'quantity' => 1, 'price' => 1]), 'POST', '/bids', ['action' => 'bid.place'], Models\BidResult::class];
        yield 'bids.get' => [fn (Client $c) => $c->bids->get('BID-x'), 'GET', '/bids/BID-x', ['bid' => []], Models\OwnBid::class];
        yield 'bids.update' => [fn (Client $c) => $c->bids->update('BID-x', ['price' => 2]), 'PATCH', '/bids/BID-x', ['action' => 'bid.update'], Models\BidResult::class];
        yield 'bids.cancel' => [fn (Client $c) => $c->bids->cancel('BID-x'), 'DELETE', '/bids/BID-x', ['action' => 'bid.cancel'], Models\BidResult::class];
        yield 'bids.renew' => [fn (Client $c) => $c->bids->renew('BID-x'), 'POST', '/bids/BID-x/renew', ['action' => 'bid.renew'], Models\BidResult::class];
        yield 'offers.list' => [fn (Client $c) => $c->offers->list(), 'GET', '/offers', ['offers' => [[]], '@page' => $page], Models\PortfolioWine::class];
        yield 'offers.create' => [fn (Client $c) => $c->offers->create(['stock_type' => 'cru', 'quantity' => 1, 'price' => 1]), 'POST', '/offers', ['action' => 'offer.list'], Models\OfferResult::class];
        yield 'offers.get' => [fn (Client $c) => $c->offers->get('OFFER-x'), 'GET', '/offers/OFFER-x', ['offer' => []], Models\PortfolioWine::class];
        yield 'offers.update' => [fn (Client $c) => $c->offers->update('OFFER-x', ['price' => 2]), 'PATCH', '/offers/OFFER-x', ['action' => 'offer.update'], Models\OfferResult::class];
        yield 'offers.remove' => [fn (Client $c) => $c->offers->remove('OFFER-x'), 'DELETE', '/offers/OFFER-x', ['action' => 'offer.remove'], Models\OfferRemoveResult::class];
        yield 'portfolio.list' => [fn (Client $c) => $c->portfolio->list(), 'GET', '/portfolio', ['wines' => [[]], '@page' => $page], Models\PortfolioWine::class];
        yield 'trades.listPurchases' => [fn (Client $c) => $c->trades->listPurchases(), 'GET', '/trades/purchases', ['purchases' => [[]], '@page' => $page], Models\Purchase::class];
        yield 'trades.listSales' => [fn (Client $c) => $c->trades->listSales(), 'GET', '/trades/sales', ['sales' => [[]], '@page' => $page], Models\Sale::class];
        yield 'inbondAddresses.list' => [fn (Client $c) => $c->inbondAddresses->list(), 'GET', '/addresses/inbond', ['inbond_addresses' => [[]]], Models\InbondAddress::class];
        yield 'inbondAddresses.add' => [fn (Client $c) => $c->inbondAddresses->add(['inbond_address_id' => 12, 'account_number' => 'ACC-1']), 'POST', '/addresses/inbond', ['action' => 'inbond_address.add'], Models\InbondAddressResult::class];
        yield 'inbondAddresses.remove' => [fn (Client $c) => $c->inbondAddresses->remove(12), 'DELETE', '/addresses/inbond/12', ['action' => 'inbond_address.remove'], Models\InbondAddressResult::class];
        yield 'confirmations.get' => [fn (Client $c) => $c->confirmations->get('cnf_x'), 'GET', '/confirmations/cnf_x', ['confirmation' => []], Models\Confirmation::class];
        yield 'confirmations.confirm' => [fn (Client $c) => $c->confirmations->confirm('cnf_x'), 'POST', '/confirmations/cnf_x', ['action' => 'bid.renew'], Models\BidResult::class];
        yield 'confirmations.cancel' => [fn (Client $c) => $c->confirmations->cancel('cnf_x'), 'DELETE', '/confirmations/cnf_x', '@empty', 'void'];
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('endpoints')]
    public function testEachEndpoint(\Closure $call, string $method, string $path, mixed $data, string $returns): void
    {
        if ($data === '@binary') {
            $response = new Response(200, ['Content-Type' => 'image/jpeg'], 'jpeg-bytes');
        } elseif ($data === '@empty') {
            $response = new Response(204);
        } else {
            self::assertIsArray($data);
            $meta = isset($data['@page']) ? ['pagination' => $data['@page']] : [];
            unset($data['@page']);
            $response = self::ok($data, 200, [], $meta);
        }
        $client = $this->partner([$response]);
        $result = $call($client);

        self::assertSame($method, $this->request()->getMethod());
        self::assertSame('/api/v1' . $path, $this->request()->getUri()->getPath());

        if ($returns === 'void') {
            self::assertNull($result);
        } elseif ($returns === 'string') {
            self::assertSame('jpeg-bytes', $result);
        } elseif ($result instanceof Page) {
            self::assertContainsOnlyInstancesOf($returns, $result->items);
            self::assertCount(1, $result);
        } elseif ($result instanceof Write) {
            self::assertSame($returns, $result->resultClass);
            self::assertInstanceOf($returns, $result->result);
        } elseif (is_array($result)) {
            self::assertCount(1, $result);
            self::assertContainsOnlyInstancesOf($returns, $result);
        } else {
            self::assertInstanceOf($returns, $result);
        }
    }

    public function testEveryResourceIsCovered(): void
    {
        $covered = [];
        foreach (self::endpoints() as $name => $_) {
            $covered[] = $name;
        }
        $methods = [];
        $client = (new \ReflectionClass(Client::class));
        foreach ($client->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            foreach ((new \ReflectionClass($type->getName()))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->getName() !== '__construct') {
                    $methods[] = $property->getName() . '.' . $m->getName();
                }
            }
        }
        $methods[] = 'ping';

        sort($methods);
        sort($covered);
        self::assertSame($methods, $covered);
    }
}
