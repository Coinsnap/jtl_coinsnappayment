<?php
declare(strict_types=1);
namespace Coinsnap\Client;

class Store extends AbstractClient{
    
    /**
     * @return \Coinsnap\Result\Store[int $code, array $result]
     */
    public function getStore($storeId): \Coinsnap\Result\Store
    {
        $url = $this->getApiUrl().COINSNAP_SERVER_PATH.'/' . urlencode($storeId);
        $headers = $this->getRequestHeaders();
        $method = 'GET';
        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {            
            $json_decode = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if(json_last_error() === JSON_ERROR_NONE){
                return new \Coinsnap\Result\Store($json_decode);
            }
            else {
                return new \Coinsnap\Result\Store(array('result' => false, 'error' => 'Coinsnap server is not available'));
            }
        }
        else {
            throw $this->getExceptionByStatusCode($method, $url, (int)$response->getStatus(), $response->getBody());
        }
    }

    /**
     * @return \Coinsnap\Result\Store[]
     */
    public function getStores(): array
    {
        $url = $this->getApiUrl().COINSNAP_SERVER_PATH;
        $headers = $this->getRequestHeaders();
        $method = 'GET';
        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            $stores_array = [];
            $json_decode = json_decode($response->getBody(), true);
            
            foreach ($json_decode as $item) {                
                $item = new \Coinsnap\Result\Store($item);
                $stores_array[] = $item;
            }
            return $stores_array;
        }
        else {
            throw $this->getExceptionByStatusCode($method, $url, (int)$response->getStatus(), $response->getBody());
        }
    }
    
    /**
     * For BTCPay server only
     * @return \Coinsnap\Result\Store[int $code, array $result]
     */
    public function getStorePaymentMethods($storeId): \Coinsnap\Result\Store {
        
        $url = $this->getApiUrl().COINSNAP_SERVER_PATH.'/' . urlencode($storeId) . '/payment-methods';
        $headers = $this->getRequestHeaders();
        $method = 'GET';
        $response = $this->getHttpClient()->request($method, $url, $headers);
        if ($response->getStatus() === 200) {

            $json_decode = json_decode($response->getBody(), true, 512, JSON_INVALID_UTF8_IGNORE);

            $result = array('response' => $json_decode);
            if(count($json_decode) > 0){
                    
                $result['onchain'] = false;
                $result['lightning'] = false;
                $result['usdt'] = false;
                    
                foreach($json_decode as $storePaymentMethod){
                        
                    if($storePaymentMethod['enabled'] > 0){
                        $result['paymentmethods'][] = $storePaymentMethod['paymentMethodId'];
                    }
                    if($storePaymentMethod['enabled'] > 0 && stripos($storePaymentMethod['paymentMethodId'],'BTC') !== false){
                        $result['onchain'] = true;
                    }
                    if($storePaymentMethod['enabled'] > 0 && ($storePaymentMethod['paymentMethodId'] === 'Lightning' || stripos($storePaymentMethod['paymentMethodId'],'-LN') !== false)) {
                        $result['lightning'] = true;
                    }
                    if($storePaymentMethod['enabled'] > 0 && stripos($storePaymentMethod['paymentMethodId'],'USDT') !== false){
                        $result['usdt'] = true;
                    }
                }
            }
            return new \Coinsnap\Result\Store(array('code' => $response->getStatus(), 'result' => $result));
        }
        else {
            throw $this->getExceptionByStatusCode($method, $url, (int)$response->getStatus(), $response->getBody());
        }
    }
}
