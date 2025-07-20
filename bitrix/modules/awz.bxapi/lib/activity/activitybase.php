<?php
namespace Awz\BxApi\Activity;

use Awz\BxApi\Helper;
use Bitrix\Main\Application;
use Awz\BxApi\Custom\WorkAppLogTable;
use Bitrix\Main\Config\Configuration;

abstract class ActivityBase {

    const TYPE_ROBOT = 'robot';
    const CODE = '';

    const LIMIT_TYPE_VIP = 'vip';
    const LIMIT_TYPE_HIGT = 'higt';
    const LIMIT_TYPE_LOW = 'low';
    const LIMIT_TYPE_MEDIUM = 'medium';
    const LIMIT_TYPE_BAN = 'ban';

    private static $logger;

    protected static function setLogger(\Bitrix\Main\Diag\Logger $logger = null){
        self::$logger = $logger;
    }
    protected static function getLogger(){
        return self::$logger;
    }
    protected static function getDefParams(string $name): array
    {
        $defParams = [
            'errorText'=> [
                'Name'=> [
                    'ru'=>'Текст ошибки'
                ],
                'Type'=>'string',
                'Default'=>'',
                'Multiple'=>'N',
            ]
        ];
        return $defParams[$name];
    }

    protected static function getCodeFromCode(string $type, string $code): string
    {
        if($type == self::TYPE_ROBOT)
            return $code.'_r';
        return $code;
    }

    protected static function getLogId(string $app="", string $domain="", string $type = "unknown", string $wfId = "", $priority = ""): array
    {
        static $log_data;
        if(empty($log_data)){
            $log_data = [];
            $request = Application::getInstance()->getContext()->getRequest();
            $requestData = $request->toArray();
            if(!$wfId){
                if($requestData['workflow_id']){
                    $wfId = $requestData['workflow_id'];
                }
            }
            if($wfId){
                $r = WorkAppLogTable::getList([
                    'select'=>['*'],
                    'filter'=>[
                        '=PORTAL'=>$domain,
                        '=APP'=>$app,
                        '=ENTITY_ID'=>$wfId,
                    ],
                    'limit'=>1
                ]);
                if($data = $r->fetch()){
                    $log_data = $data;
                }else{
                    $fields = [
                        'PORTAL'=>$domain,
                        'APP'=>$app,
                        'ENTITY_ID'=>$wfId,
                        'DATE_ADD'=>\Bitrix\Main\Type\DateTime::createFromTimestamp(time()),
                        'PARAMS'=>[
                            'type'=>$type,
                            'priority'=>$priority,
                            'document_id'=>$requestData['document_id'],
                            'document_type'=>$requestData['document_type'],
                            'properties'=>$requestData['properties']
                        ]
                    ];
                    $rAdd = WorkAppLogTable::add($fields);
                    $fields['ID'] = $rAdd->getId();
                    $log_data = $fields;
                }
            }
        }
        return $log_data;
    }

    protected static function setLogStatus(\Bitrix\Main\Result $result){
        $logItem = self::getLogId();
        if(!empty($logItem)){
            $params = $logItem['PARAMS'];
            if($result->isSuccess()){
                $params['exists_time'] = microtime(true);
            }else{
                $params['errors'] = $result->getErrorMessages();
                $params['exists_time'] = microtime(true);
            }

            $requestData = self::getRequestData();
            if($requestData['awz_task_id'])
                $params['awz_task_id'] = $requestData['awz_task_id'];

            WorkAppLogTable::update(['ID'=>$logItem['ID']],['PARAMS'=>$params]);
        }
    }

    protected static function sendTurn(string $domain, string $app_id, array $requestData = [], string $priority = ''){
        if(!$requestData['proxy_url'] && !$requestData['proxy'] && !self::isJsonRequest())
        {
            $requestData['Queue'] = ($priority == static::LIMIT_TYPE_HIGT) ? static::LIMIT_TYPE_HIGT : static::LIMIT_TYPE_LOW;
            $requestData['proxy_url'] = 'https://'.Application::getInstance()->getContext()->getServer()->getHttpHost()
                .'/bitrix/services/main/ajax.php?action=awz:bxapi.api.fullactivity.activity&domain='.
                $domain.'&app='.$app_id.'&key='.$requestData['key'].'&method='.$requestData['method'].'&proxy=1';
            $settings = Configuration::getInstance('awz.bxapi');
            $apiUrl = $settings->get('bp_api')['URL']."?access_token=".$settings->get('bp_api')['KEY'];
            $jsonData = [
                'json'=>$requestData
            ];

            $httpClient = new \Bitrix\Main\Web\HttpClient();
            $httpClient->waitResponse(false);
            $httpClient->disableSslVerification();
            $httpClient->setHeader('Content-Type', 'application/json', true);
            $httpClient->post($apiUrl, \Bitrix\Main\Web\Json::encode($jsonData));

            return true;
        }
        return false;
    }

    protected static function returnBp(\Awz\BxApi\App $app, \Bitrix\Main\Result $result, array $returnParams =[], $context=null){

        /* @var $context \Awz\BxApi\Api\Scopes\Controller */
        $log = self::getLogger() ?? $context?->getLogger();

        if(!$result->isSuccess()){
            $returnParams['errorText'] = implode("; ",$result->getErrorMessages());
        }
        $requestData = self::getRequestData();

        if(!$requestData['event_token']){
            $result->addError(
                new \Bitrix\Main\Error('Не передан event_token с бизнес процесса')
            );
            self::setLogStatus($result);
            return $result;
        }

        $retArr = array(
            'event_token'=>$requestData['event_token'],
            'return_values'=>$returnParams
        );

        $log?->debug(
            "[returnParams]\n{date}\n{returnParams}\n",
            ['returnParams' => $returnParams]
        );

        if($requestData['auth']['access_token']){
            $app->setAuth($requestData['auth']);
        }
        if(!$app->getEndpoint()){
            $result->addError(
                new \Bitrix\Main\Error('Нет токена доступа к порталу')
            );
            self::setLogStatus($result);
            return $result;
        }

        $resultBp = $app->postMethod('bizproc.event.send', $retArr);
        if(!$resultBp->isSuccess()){
            $result->addErrors($resultBp->getErrors());
        }else{
            $log?->error(
                "[resultBp]\n{date}\n{resultBp}\n",
                ['resultBp' => $resultBp->getData()]
            );
        }

        self::setLogStatus($result);

        return $result;
    }

    protected static function getAuthApp(string $app_id, string $domain){
        $result = new \Bitrix\Main\Result;
        $app = new \Awz\bxApi\App(array(
            'APP_ID'=>$app_id,
            'APP_SECRET_CODE'=>Helper::getSecret($app_id)
        ));

        $portalData = $app->getCurrentPortalData($domain);

        if(!$portalData){
            $result->addError(
                new \Bitrix\Main\Error('Токен для доступа к порталу не найден')
            );
            return $result;
        }
        $resultAuth = $app->setAuth($portalData['TOKEN']);
        if($resultAuth->isSuccess()){
            $result->setData(['app'=>$app, 'portalData'=>$portalData]);
        }else{
            $result->addErrors($resultAuth->getErrors());
        }
        return $result;
    }

    protected static function isJsonRequest(): bool
    {
        $request = Application::getInstance()->getContext()->getRequest();
        return $request->getHeaders()->getContentType() == 'application/json';
    }

    protected static function getRequestData(){
        static $requestData;
        if(!$requestData){
            $request = Application::getInstance()->getContext()->getRequest();
            $requestData = $request->toArray();
            if(self::isJsonRequest()){
                $jsonPayload = new \Bitrix\Main\Engine\JsonPayload();
                $requestData = $jsonPayload->getData();
            }
        }
        return $requestData;
    }

    protected static function getPriority(string $appId, string $domain): string
    {
        $main_query = new \Bitrix\Main\Entity\Query(WorkAppLogTable::getEntity());
        $main_query->registerRuntimeField("CNT", ['expression' => ['COUNT(*)','ID'], 'data_type'=>'integer']);
        $main_query->setSelect(["CNT"]);
        $main_query->setFilter([
            '=PORTAL'=>$domain,
            '=APP'=>$appId,
            '>=DATE_ADD'=>\Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime(date('d.m.Y')))
        ]);
        $result_chain = $main_query->setLimit(null)->setOffset(null)->exec();
        $result_chain = $result_chain->fetch();
        $cntActiveDay = $result_chain ? intval($result_chain['CNT']) : 0;

        $main_query = new \Bitrix\Main\Entity\Query(WorkAppLogTable::getEntity());
        $main_query->registerRuntimeField("CNT", ['expression' => ['COUNT(*)','ID'], 'data_type'=>'integer']);
        $main_query->setSelect(["CNT"]);
        $main_query->setFilter([
            '=PORTAL'=>$domain,
            '=APP'=>$appId,
            '>=DATE_ADD'=>\Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime(date('d.m.Y H').':00:00'))
        ]);
        $result_chain = $main_query->setLimit(null)->setOffset(null)->exec();
        $result_chain = $result_chain->fetch();
        $cntActiveHour = $result_chain ? intval($result_chain['CNT']) : 0;

        if($cntActiveHour < 60) return static::LIMIT_TYPE_VIP;
        if($cntActiveHour < 180) return static::LIMIT_TYPE_HIGT;
        if($cntActiveDay < 18000) return static::LIMIT_TYPE_LOW;
        return static::LIMIT_TYPE_BAN;
    }

    abstract public static function getParams(string $type): array;

    abstract public static function getCode(string $type): string;
    abstract public static function getName(string $type): string;
    abstract public static function getDescription(string $type): string;

    abstract public static function run(string $domain, string $app_id, string $type): \Bitrix\Main\Result;

}
