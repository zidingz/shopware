<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Tests\Functional\Controllers\Frontend;

use Enlight_Components_Test_Plugin_TestCase;
use Enlight_Controller_Request_RequestHttp;
use LogicException;
use Shopware\Bundle\OrderBundle\Service\CalculationServiceInterface;
use Shopware\Components\ShopRegistrationServiceInterface;
use Shopware\Models\Customer\Group;
use Shopware\Models\Order\Order;
use Shopware\Models\Shop\Repository;
use Shopware\Models\Shop\Shop;

class CheckoutTest extends Enlight_Components_Test_Plugin_TestCase
{
    public const ARTICLE_NUMBER = 'SW10239';
    public const USER_AGENT = 'Mozilla/5.0 (Android; Tablet; rv:14.0) Gecko/14.0 Firefox/14.0';

    /**
     * Reads the user agent black list and test if the bot can add an article
     *
     * @ticket SW-6411
     */
    public function testBotAddBasketArticle()
    {
        $botBlackList = ['digout4u', 'fast-webcrawler', 'googlebot', 'ia_archiver', 'w3m2', 'frooglebot'];
        foreach ($botBlackList as $userAgent) {
            if (!empty($userAgent)) {
                $sessionId = $this->addBasketArticle($userAgent);
                static::assertNotEmpty($sessionId);
                $basketId = Shopware()->Db()->fetchOne(
                    'SELECT id FROM s_order_basket WHERE sessionID = ?',
                    [$sessionId]
                );
                static::assertEmpty($basketId);
            }
        }

        Shopware()->Modules()->Basket()->sDeleteBasket();
    }

    /**
     * Test if an normal user can add an article
     *
     * @ticket SW-6411
     */
    public function testAddBasketArticle()
    {
        $sessionId = $this->addBasketArticle(include __DIR__ . '/fixtures/UserAgent.php');
        static::assertNotEmpty($sessionId);
        $basketId = Shopware()->Db()->fetchOne(
            'SELECT id FROM s_order_basket WHERE sessionID = ?',
            [$sessionId]
        );
        static::assertNotEmpty($basketId);

        Shopware()->Modules()->Basket()->sDeleteBasket();
    }

    /**
     * Tests that price calculations of the basket do not differ from the price calculation in the Order
     * for customer group
     */
    public function testCheckoutForNetOrders()
    {
        $net = true;
        $this->runCheckoutTest($net);
    }

    /**
     * Tests that price calculations of the basket do not differ from the price calculation in the Order
     * for customer group
     */
    public function testCheckoutForGrossOrders()
    {
        $net = false;
        $this->runCheckoutTest($net);
    }

    /**
     * Tests that the addArticle-Action returns HTML
     */
    public function testAddToBasketReturnsHtml()
    {
        $this->reset();
        $this->Request()->setMethod('POST');
        $this->Request()->setHeader('User-Agent', self::USER_AGENT);
        $this->Request()->setParam('sQuantity', 5);
        $this->Request()->setParam('sAdd', self::ARTICLE_NUMBER);
        $this->Request()->setParam('isXHR', 1);

        $response = $this->dispatch('/checkout/addArticle', true);
        static::assertStringContainsString('<div class="modal--checkout-add-article">', $response->getBody());

        Shopware()->Modules()->Basket()->sDeleteBasket();
    }

    /**
     * Tests that products can't add to basket over HTTP-GET
     */
    public function testAddBasketOverGetFails()
    {
        $this->expectException(LogicException::class);

        $this->reset();
        $this->Request()->setHeader('User-Agent', self::USER_AGENT);
        $this->Request()->setParam('sQuantity', 5);
        $this->dispatch('/checkout/addArticle/sAdd/' . self::ARTICLE_NUMBER);

        Shopware()->Modules()->Basket()->sDeleteBasket();
    }

    public function testRequestPaymentWithoutAGB(): void
    {
        // Login
        $this->loginFrontendUser();

        // Add article to basket
        $this->addBasketArticle(self::USER_AGENT, 5);

        // Confirm checkout
        $this->reset();
        $this->Request()->setMethod('POST');
        $this->Request()->setHeader('User-Agent', self::USER_AGENT);
        $this->dispatch('/checkout/confirm');

        // Finish checkout
        $this->reset();
        $this->Request()->setMethod('POST');
        $this->Request()->setHeader('User-Agent', self::USER_AGENT);
        $this->dispatch('/checkout/payment');

        static::assertTrue($this->View()->getAssign('sAGBError'));

        // Got redirected back
        static::assertSame('confirm', $this->Request()->getActionName());

        // Logout frontend user
        Shopware()->Modules()->Admin()->logout();
    }

    public function testRequestPaymentWithoutServiceAgreement(): void
    {
        // Login
        $this->loginFrontendUser();

        // Add article to basket
        $this->addBasketArticle(self::USER_AGENT, 5);

        Shopware()->Db()->beginTransaction();
        $this->setConfig('serviceAttrField', 'attr1');
        Shopware()->Db()->exec('UPDATE s_articles_attributes SET attr1 = 1');

        // Confirm checkout
        $this->reset();
        $this->Request()->setMethod('POST');
        $this->Request()->setHeader('User-Agent', self::USER_AGENT);
        $this->dispatch('/checkout/confirm');

        // Finish checkout
        $this->reset();
        $this->Request()->setMethod('POST');
        $this->Request()->setHeader('User-Agent', self::USER_AGENT);
        $this->Request()->setPost('sAGB', 'on');
        $this->dispatch('/checkout/payment');

        $this->setConfig('serviceAttrField', null);
        Shopware()->Db()->rollBack();

        static::assertFalse($this->View()->getAssign('sAGBError'));

        // Got redirected back
        static::assertSame('confirm', $this->Request()->getActionName());

        // Logout frontend user
        Shopware()->Modules()->Admin()->logout();
    }

    /**
     * Compares the calculated price from a basket with the calculated price from \Shopware\Bundle\OrderBundle\Service\CalculationService::recalculateOrderTotals()
     * It does so by creating via the frontend controllers, and comparing the amount (net & gross) with the values provided by
     * Order/Order::calculateInvoiceAmount (Which will be called when one changes / saves the order in the backend).
     *
     * Also covers a complete checkout process
     *
     * @param bool $net
     */
    public function runCheckoutTest($net = false)
    {
        $tax = $net === true ? 0 : 1;

        // Set net customer group
        $defaultShop = Shopware()->Models()->getRepository(Shop::class)->find(1);
        $previousCustomerGroup = $defaultShop->getCustomerGroup()->getKey();
        $netCustomerGroup = Shopware()->Models()->getRepository(Group::class)->findOneBy(['tax' => $tax])->getKey();
        static::assertNotEmpty($netCustomerGroup);
        Shopware()->Db()->query(
            'UPDATE s_user SET customergroup = ? WHERE id = 1',
            [$netCustomerGroup]
        );

        // Simulate checkout in frontend

        // Login
        $this->loginFrontendUser();

        // Add article to basket
        $this->addBasketArticle(self::USER_AGENT, 5);

        // Confirm checkout
        $this->reset();
        $this->Request()->setMethod('POST');
        $this->Request()->setHeader('User-Agent', self::USER_AGENT);
        $this->dispatch('/checkout/confirm');

        // Finish checkout
        $this->reset();
        $this->Request()->setMethod('POST');
        $this->Request()->setHeader('User-Agent', self::USER_AGENT);
        $this->Request()->setParam('sAGB', 'on');
        $this->dispatch('/checkout/finish');

        // Logout frontend user
        Shopware()->Modules()->Admin()->logout();

        // Revert customer group
        Shopware()->Db()->query(
            'UPDATE s_user SET customergroup = ? WHERE id = 1',
            [$previousCustomerGroup]
        );

        // Fetch created order
        $orderId = Shopware()->Db()->fetchOne(
            'SELECT id FROM s_order ORDER BY ID DESC LIMIT 1'
        );
        /** @var Order $order */
        $order = Shopware()->Models()->getRepository(Order::class)->find($orderId);

        // Save invoiceAmounts for comparison
        $previousInvoiceAmount = $order->getInvoiceAmount();
        $previousInvoiceAmountNet = $order->getInvoiceAmountNet();

        // Simulate backend order save
        /** @var CalculationServiceInterface $calculationService */
        $calculationService = Shopware()->Container()->get(CalculationServiceInterface::class);
        $calculationService->recalculateOrderTotals($order);

        // Assert messages
        $message = 'InvoiceAmount' . ($net ? ' (net shop)' : '') . ': ' . $previousInvoiceAmount . ' from sBasket, ' . $order->getInvoiceAmount() . ' from getInvoiceAmount';
        $messageNet = 'InvoiceAmountNet' . ($net ? ' (net shop)' : '') . ': ' . $previousInvoiceAmountNet . ' from sBasket, ' . $order->getInvoiceAmountNet() . ' from getInvoiceAmountNet';

        // Test that sBasket calculation matches calculateInvoiceAmount
        static::assertEquals($order->getInvoiceAmount(), $previousInvoiceAmount, $message);
        static::assertEquals($order->getInvoiceAmountNet(), $previousInvoiceAmountNet, $messageNet);

        Shopware()->Modules()->Basket()->sDeleteBasket();
    }

    public function testRedirectShippingPaymentPageOnEmptyBasket()
    {
        $this->loginFrontendUser();

        $this->Request()->setMethod('GET');
        $this->Request()->setHeader('User-Agent', self::USER_AGENT);
        $response = $this->dispatch('/checkout/shippingPayment');

        $locationHeader = array_filter($response->getHeaders(), static function (array $header) {
            return stripos($header['name'], 'location') === 0;
        });

        static::assertTrue($response->isRedirect());
        static::assertEquals(302, $response->getHttpResponseCode());
        static::assertCount(2, $locationHeader); // Known bug due to Symfony migration
        $locationHeader = array_pop($locationHeader);
        static::assertStringContainsString('/checkout/cart', $locationHeader['value']);
    }

    /**
     * Login as a frontend user
     */
    public function loginFrontendUser()
    {
        Shopware()->Front()->setRequest(new Enlight_Controller_Request_RequestHttp());
        $user = Shopware()->Db()->fetchRow(
            'SELECT id, email, password, subshopID, language FROM s_user WHERE id = 1'
        );

        /** @var Repository $repository */
        $repository = Shopware()->Models()->getRepository(Shop::class);
        $shop = $repository->getActiveById($user['language']);

        Shopware()->Container()->get(ShopRegistrationServiceInterface::class)->registerShop($shop);

        Shopware()->Session()->set('Admin', true);
        Shopware()->System()->_POST = [
            'email' => $user['email'],
            'passwordMD5' => $user['password'],
        ];
        Shopware()->Modules()->Admin()->sLogin(true);
    }

    /**
     * Fires the add article request with the given user agent
     *
     * @param string $userAgent
     * @param int    $quantity
     *
     * @return string session id
     */
    private function addBasketArticle($userAgent, $quantity = 1)
    {
        $this->reset();
        $this->Request()->setMethod('POST');
        $this->Request()->setHeader('User-Agent', $userAgent);
        $this->Request()->setParam('sQuantity', $quantity);
        $this->Request()->setParam('sAdd', self::ARTICLE_NUMBER);
        $this->dispatch('/checkout/addArticle', true);

        return Shopware()->Container()->get('sessionid');
    }
}
