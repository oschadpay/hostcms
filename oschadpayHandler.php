<?php
	class Shop_Payment_System_HandlerXX extends Shop_Payment_System_Handler
	{
		// -------------------------------------------------------------------------------------------------
		// Настройки модуля
		// -------------------------------------------------------------------------------------------------
		protected $_oschadpay_merchant_id = 'ID мерчанта'; // ID мерчанта, в лчином кабинете Oschadpay
		protected $_oschadpay_redirect_mode = 0; // 1 - с перенаправлением, 0 - без перенаправления
		protected $_oschadpay_secret_key = 'секретный ключ'; // секретный ключ
		protected $_oschadpay_language = 'ru'; // язык, платежной системы
		// id валюты, в которой будет производиться платеж
		protected $_oschadpay_currency_id = 1; // 1 - рубли (RUR), 2 - евро (EUR), 3 - доллары (USD)
		// -------------------------------------------------------------------------------------------------
		// конец настроек
		// -------------------------------------------------------------------------------------------------
		
		
		protected $_oschadpay_coefficient = 1; // коэффициент перерасчета при оплате
		/**
			* @return Shop_Payment_System_Handler|Shop_Payment_System_Handler9
			* Метод, запускающий выполнение обработчика. Вызывается на 4-ом шаге оформления заказа
		*/
		public function execute()
		{
			parent::execute();
			
			$this->printNotification();
			
			return $this;
		}
		
		/**
			* @return Shop_Payment_System_Handler|Shop_Payment_System_Handler9
			*
		*/
		protected function _processOrder()
		{
			parent::_processOrder();
			
			// Установка XSL-шаблонов в соответствии с настройками в узле структуры
			$this->setXSLs();
			
			// Отправка писем клиенту и пользователю
			$this->send();
			
			return $this;
		}
		
		/**
			* @return mixed
			* вычисление суммы товаров заказа
		*/
		public function getSumWithCoeff()
		{
			return Shop_Controller::instance()->round(($this->_oschadpay_currency_id > 0
            && $this->_shopOrder->shop_currency_id > 0
            ? Shop_Controller::instance()->getCurrencyCoefficientInShopCurrency(
			$this->_shopOrder->Shop_Currency,
			Core_Entity::factory('Shop_Currency', $this->_oschadpay_currency_id)
            )
            : 0) * $this->_shopOrder->getAmount() * $this->_oschadpay_coefficient);
		}
		
		/**
			* @return mixed|void
		*/
		public function getInvoice()
		{
			return $this->getNotification();
		}
		
		/**
			* @return mixed|void
			* печатает форму отправки запроса на сайт платёжной системы
		*/
		public function getNotification()
		{
			$redirect_mode = $this->_oschadpay_redirect_mode; // тестовый режим
			$order_id = $this->_shopOrder->id; // номер заказа
			$description = 'Оплата заказа №' . $order_id; // описание покупки
			$oShop_Currency = Core_Entity::factory('Shop_Currency')->find($this->_oschadpay_currency_id);
			$currency_code = $oShop_Currency->code; // валюта
			if ($currency_code == 'RUR') {$currency_code = 'RUB';}
			$currency_name = $oShop_Currency->name; // валюта
			$oSite_Alias = $this->_shopOrder->Shop->Site->getCurrentAlias();
			$site_alias = !is_null($oSite_Alias) ? $oSite_Alias->name : '';
			$shop_path = $this->_shopOrder->Shop->Structure->getPath();
			$protocol = isset($_SERVER["HTTPS"]) ? 'https://' : 'http://';
			$server_callback_url = $protocol . $site_alias . $shop_path . 'cart/?paymentcallback=oschadpay'; //url на который будет отправлено уведомление о состоянии платежа
			$response_url = $protocol . $site_alias . $shop_path . 'cart/?order_id=' . $order_id . '&payment=success'; //url на который будет перенаправлен плательщик после успешной оплаты
			
			$formFields = array(
			'order_id' => $order_id . OschadpayForm::ORDER_SEPARATOR . time(),
			'merchant_id' => $this->_oschadpay_merchant_id, // id мерчанта
			'order_desc' => $description,
			'currency' => $currency_code,
			'server_callback_url' => $server_callback_url,
			'response_url' => $response_url,
			'lang' => $this->_oschadpay_language, // язык, который будет использован на мерчанте
			);
			
			if ($this->_oschadpay_redirect_mode == 1)
			{
				$formFields['amount'] = round($this->getSumWithCoeff()*100);
				$formFields['signature'] = OschadpayForm::getSignature($formFields,$this->_oschadpay_secret_key);
				$oschadpayArgsArray = array();
				foreach ($formFields as $key => $value) {
					$oschadpayArgsArray[] = "<input type='hidden' name='$key' value='$value'/>";
				}
				echo '	<form action="' . OschadpayForm::URL . '" method="post" id="oschadpay_payment_form">
				' . implode('', $oschadpayArgsArray) . '
				<input type="submit" id="submit_oschadpay_payment_form" />
				<script type="text/javascript">
				</script>
				</form>';
			}else{
				$formFields['amount'] = round($this->getSumWithCoeff()*100);
				$formFields['signature'] = OschadpayForm::getSignature($formFields,$this->_oschadpay_secret_key);
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, 'https://api.oschadpay.com.ua/api/checkout/url/');
				curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('request'=>$formFields)));
				$result = json_decode(curl_exec($ch));
				if ($result->response->response_status == 'failure'){
					echo $result->response->error_message;
					exit;
				}
				echo '<script src="https://code.jquery.com/jquery-1.9.1.min.js"></script>
				<script src="https://api.oschadpay.com.ua/static_common/v1/checkout/ipsp.js"></script>
				<div id="checkout">
				<div id="checkout_wrapper"></div>
				</div>
				<script>
				var checkoutStyles = {
				"html , body" : {
				"overflow" : "hidden"
				},
				".col.col-shoplogo" : {
				"display" : "none"
				},
				".col.col-language" : {
				"display" : "none"
				},
				".pages-checkout" : {
				"background" : "transparent"
				},
				".col.col-login" : {
				"display" : "none"
				},
				".pages-checkout .page-section-overview" : {
				"background" : "#fff",
				"color" : "#252525",
				"border-bottom" : "1px solid #dfdfdf"
				},
				".col.col-value.order-content" : {
				"color" : "#252525"
				},
				".page-section-footer" : {
				"display" : "none"
				},
				".page-section-tabs" : {
				"display" : "none"
				},
				".page-section-shopinfo" : {
				"display": "none"
				},
				}
				function checkoutInit(url, val) {
				$ipsp("checkout").scope(function() {
				this.setCheckoutWrapper("#checkout_wrapper");
				this.addCallback(__DEFAULTCALLBACK__);
				this.setCssStyle(checkoutStyles);
				this.action("show", function(data) {
				$("#checkout_loader").remove();
				$("#checkout").show();
				});
				this.action("hide", function(data) {
				$("#checkout").hide();
				});
				this.action("resize", function(data) {
				$("#checkout_wrapper").width("100%").height(data.height);
				});
				this.loadUrl(url);
				});
				};
				checkoutInit("' . $result->response->checkout_url . '");
				</script>';
			}
		}
		
		/**
			* @return bool
			* обработка ответа от платёжной системы
		*/
		public function paymentProcessing()
		{
			$this->ProcessResult();
			
			return TRUE;
		}
		
		/**
			* @return bool
			* оплачивает заказ
		*/
		function ProcessResult()
		{
			if (!$_POST['order_status']){
				return false;
			}
			$oplataSettings = array('merchant_id' => $this->_oschadpay_merchant_id,
			'secret_key' => $this->_oschadpay_secret_key,
			);
			$isPaymentValid = OschadpayForm::isPaymentValid($oplataSettings, $_POST);
			if ($isPaymentValid == true)
			{ 
				$oShop_Order = $this->_shopOrder;
				$oShop_Order->system_information = "Заказ оплачен через сервис Oschadpay. Paymant ID заказа в системе - ." . $_POST['payment_id'] . "\n";
				$oShop_Order->paid();
				$this->setXSLs();
				$this->send();		
				} else {
				echo $isPaymentValid;
			}
		}
	}
	class OschadpayForm
	{
		const RESPONCE_SUCCESS = 'success';
		const RESPONCE_FAIL = 'failure';
		const ORDER_SEPARATOR = '#';
		const SIGNATURE_SEPARATOR = '|';
		const ORDER_APPROVED = 'approved';
		const ORDER_DECLINED = 'declined';
		const URL = "https://api.oschadpay.com.ua/api/checkout/redirect/";
		public static function getSignature($data, $password, $encoded = true)
		{
			$data = array_filter($data, function($var) {
				return $var !== '' && $var !== null;
			});
			ksort($data);
			$str = $password;
			foreach ($data as $k => $v) {
				$str .= OschadpayForm::SIGNATURE_SEPARATOR . $v;
			}
			if ($encoded) {
				return sha1($str);
				} else {
				return $str;
			}
		}
		public static function isPaymentValid($oplataSettings, $response)
		{
			if ($oplataSettings['merchant_id'] != $response['merchant_id']) {
				return 'An error has occurred during payment. Merchant data is incorrect.';
			}
			if ($response['order_status'] != OschadpayForm::ORDER_APPROVED) {
				return 'An error has occurred during payment. Order is declined.';
			}
			
			$responseSignature = $response['signature'];
			if (isset($response['response_signature_string'])){
				unset($response['response_signature_string']);
			}
			if (isset($response['signature'])){
				unset($response['signature']);
			}
			if (OschadpayForm::getSignature($response, $oplataSettings['secret_key']) != $responseSignature) {
				return 'An error has occurred during payment. Signature is not valid.';
			}
			return true;
		}
	}		