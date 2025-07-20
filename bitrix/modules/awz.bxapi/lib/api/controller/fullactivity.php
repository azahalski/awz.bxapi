<?php
namespace Awz\BxApi\Api\Controller;

use Awz\BxApi\Api\Filters\AppUser;
use Awz\BxApi\Api\Filters\IsAdmin;
use Awz\BxApi\Api\Filters\PublicScope;
use Awz\BxApi\Api\Scopes\Controller;
use Awz\BxApi\Api\Scopes\Scope;
use Awz\BxApi\Api\Filters\Sign;
use Awz\BxApi\Api\Filters\AppAuth;
use Awz\BxApi\Api\Filters\AppAuthActivity;
use Awz\BxApi\App;
use Awz\BxApi\Helper;
use Awz\BxApi\TokensTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security;
use Bitrix\Main\Engine\ActionFilter;
use Awz\BxApi\Custom\WorkAppLogTable;

Loc::loadMessages(__FILE__);

class FullActivity extends Controller
{

    const TYPE_BP = 'bp';
    const TYPE_ROBOT = 'robot';
    const ACTIVITY_NS = "\\Awz\\BxApi\\Activity\\Types\\";

    public function activityLists(string $domain = ''){

        $codes = [
            'zahalski.bitrix24.by' => [
            ],
            'all'=>[
            ]
        ];

        if($domain === '' || !isset($codes[$domain])){
            return $codes['all'];
        }else{
            return array_merge($codes['all'], $codes[$domain]);
        }
    }

    public function configureActions()
    {
        $config = [
            'activity'=>[
                'prefilters' => [
                    new ActionFilter\ContentType([
                        ActionFilter\ContentType::JSON,
                        "application/x-www-form-urlencoded"
                    ]),
                    new AppAuthActivity()
                ]
            ],
            'getActivity'=>[
                'prefilters' => [
                    new Sign(
                        ['domain','key','s_id','app_id'], [],
                        Scope::createFromCode('signed')
                    ),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'), ['signed']
                    )
                ]
            ],
            'activityInfo'=>[
                'prefilters' => [
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user')
                    ),
                    new AppUser(
                        [], [],
                        Scope::createFromCode(
                            'bxuser',
                            new \Bitrix\Main\Type\Dictionary(['userId'=>0])
                        ),
                        ['user']
                    ),
                    new IsAdmin(
                        [], [],
                        Scope::createFromCode('bxadmin'),
                        ['user', 'bxuser']
                    )
                ]
            ],
            'list'=> [
                'prefilters' => [
                    /*new Sign(
                        ['domain','key','s_id','app_id'], [],
                        Scope::createFromCode('signed')
                    ),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'), ['signed']
                    )*/
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user')
                    ),
                    new AppUser(
                        [], [],
                        Scope::createFromCode(
                            'bxuser',
                            new \Bitrix\Main\Type\Dictionary(['userId'=>0])
                        ),
                        ['user']
                    ),
                    new IsAdmin(
                        [], [],
                        Scope::createFromCode('bxadmin'),
                        ['user', 'bxuser']
                    )
                ]
            ],
            'stat'=> [
                'prefilters' => [
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user')
                    ),
                    new AppUser(
                        [], [],
                        Scope::createFromCode(
                            'bxuser',
                            new \Bitrix\Main\Type\Dictionary(['userId'=>0])
                        ),
                        ['user']
                    ),
                    new IsAdmin(
                        [], [],
                        Scope::createFromCode('bxadmin'),
                        ['user', 'bxuser']
                    )
                ]
            ],
            'check'=>[
                'prefilters' => [
                    new PublicScope()
                ]
            ],
            'forward'=>[
                'prefilters' => [
                    /*new ActionFilter\ContentType([
                        ActionFilter\ContentType::JSON,
                        "application/x-www-form-urlencoded"
                    ]),*/
                ]
            ]
        ];

        return $config;
    }

    protected function getMinParams(string $className, string $type): array
    {
        $classNameNs = self::ACTIVITY_NS.$className;

        $item = array(
            'code'=>$classNameNs::getCode($type),
            'name'=>$classNameNs::getName($type),
            'desc'=>$classNameNs::getDescription($type),
            'method'=>$className,
            'tags'=>['awz']
        );

        if(method_exists($classNameNs, 'getTags')){
            $item['tags'] = $classNameNs::getTags($type);
        }

        return $item;
    }

    public function forwardAction(string $method, string $domain=""){

        if(!$domain){
            $params = [];
            $signed = $this->getRequest()->get('signed');
            if($signed){
                $signer = new Security\Sign\Signer();
                $params = $signer->unsign($signed);
                $params = unserialize(base64_decode($params), ['allowed_classes' => false]);
            }
            if(isset($params['domain'])){
                $domain = $params['domain'];
            }
        }
        if(!$domain){
            $auth = $this->getRequest()->get('auth');
            if($auth && isset($auth['domain'])){
                $domain = $auth['domain'];
            }
        }

        $configMethods = $this->configureActions();
        unset($configMethods['forward']);
        $activeMethods = array_keys($configMethods);

        $startMethod = $method;
        $activityList = array_keys($this->activityLists($domain));
        if(in_array($method, $activityList)){
            $method = 'activity';
        }

        if(!in_array($method, $activeMethods)){
            $this->addError(new Error("method {$method} not found", 200));
            return array(
                'enabled'=>array_merge($activeMethods, $activityList)
            );
        }

        return $this->run($method, $this->getSourceParametersList());
    }

    public function activityInfoAction(string $domain, string $app, string $code, string $type)
    {
        $activityList = $this->activityLists($domain);
        if(!isset($activityList[$code])){
            $this->addError(
                new Error("Активити с кодом {$code} не найдено", 100)
            );
            return null;
        }
        $className = self::ACTIVITY_NS.$code;
        $params = $className::getParams($type);
        return [
            'params'=>$params,
            'docs'=>method_exists($className, 'getDocs') ? $className::getDocs($type) : []
        ];
    }
    public function getActivityAction(string $domain, string $app_id, string $key, string $type, string $code){
        if($logger = $this->getLogger()){
            $logger->debug(
                "[fullactivity.getActivity]\n{date}\n{args}\n",
                ['args' => func_get_args()]
            );
        }
        if(!$domain || !$app_id || !$key || !$code || !$type){
            $this->addError(
                new Error('Ошибка в параметрах запроса', 100)
            );
            return null;
        }

        $activityList = $this->activityLists($domain);
        if(!isset($activityList[$code])){
            $this->addError(
                new Error("Активити с кодом {$code} не найдено", 100)
            );
            return null;
        }

        $className = self::ACTIVITY_NS.$code;
        if(!class_exists($className)){
            $this->addError(
                new Error("Активити с кодом {$code} не найдено", 100)
            );
            return null;
        }
        $params = $className::getParams($type);
        $params['HANDLER'] = str_replace('#APP_ID#', $app_id, $params['HANDLER']);
        $params['HANDLER'] .= '&key='.$key;
        return array(
            'activity'=>$params
        );

    }

    public function activityAction(string $domain, string $app_id, string $method, string $type){
        if($logger = $this->getLogger()){
            $logger->debug(
                "[fullactivity.activity]\n{date}\n{args}\n",
                ['args' => func_get_args()]
            );
        }
        $activityList = $this->activityLists($domain);
        if(!isset($activityList[$method])){
            $this->addError(
                new Error("Активити с кодом {$method} не найдено", 100)
            );
            return null;
        }

        $className = self::ACTIVITY_NS.$method;
        if(!class_exists($className)){
            $this->addError(
                new Error("Активити с кодом {$method} не найдено", 100)
            );
            return null;
        }

        $tracker = null;
        if(Loader::includeModule('awz.bxapistats')){
            $tracker = \Awz\BxApiStats\Tracker::getInstance();
            $tracker->setPortal($domain)->setAppId($app_id);
        }

        $result = $className::run($domain, $app_id, $type, $this);
        /* @var $result \Bitrix\Main\Result */

        $tracker?->addCount()?->calcHitTime();
        if($tracker)
            \Awz\BxApiStats\Tracker::saveStat($domain,$app_id);

        if($result->isSuccess()){
            return $result->getData();
        }else{
            foreach($result->getErrors() as $err){
                $this->addError($err);
            }
            return null;
        }

    }



    public function listAction(string $domain, string $app){

        $logger = $this->getLogger();
        $logger?->debug(
            "[fullactivity.list]\n{date}\n{args}\n",
            ['args' => func_get_args()]
        );
        if(!$domain || !$app){
            $this->addError(
                new Error('Ошибка в параметрах запроса', 100)
            );
            return null;
        }

        $tracker = null;
        if(Loader::includeModule('awz.bxapistats')){
            $tracker = \Awz\BxApiStats\Tracker::getInstance();
            $tracker->setPortal($domain)->setAppId($app);
        }

        if(!$this->checkRequire(['user', 'bxuser', 'bxadmin'])){
            $this->addError(
                new Error('Авторизация не найдена', 105)
            );
            return null;
        }

        $auth = TokensTable::getList(array(
            'select'=>array('*'),
            'filter'=>array('=PORTAL'=>$domain, '=APP_ID'=>$app, '=ACTIVE'=>'Y'),
            'order'=>['ID'=>'DESC']
        ))->fetch();
        if(!$auth) {
            $this->addError(
                new Error('Токен к Битрикс24 не найден', 105)
            );
            return null;
        }
        $appOb = new App(array(
            'APP_ID'=>$app,
            'APP_SECRET_CODE'=>Helper::getSecret($app)
        ));
        $appOb->setAuth($auth['TOKEN']);

        $activeCodes = [];
        $r = $appOb->callBatch([
            ['method'=>'bizproc.robot.list','params'=>[]],
            ['method'=>'bizproc.activity.list','params'=>[]],
        ]);
        if(!$r->isSuccess()){
            $this->addErrors($r->getErrors());
        }else{
            $batchData = $r->getData();

            $logger?->debug(
                "[fullactivity.list.batchData]\n{date}\n{batchData}\n",
                ['batchData' => $batchData]
            );

            foreach($batchData['result'] as $batchRow){
                foreach($batchRow as $code){
                    $activeCodes[] = $code;
                }
            }
        }

        $logger?->debug(
            "[fullactivity.list.activeCodes]\n{date}\n{activeCodes}\n",
            ['activeCodes' => $activeCodes]
        );

        $items = array();
        $activityList = $this->activityLists($domain);
        foreach($activityList as $code=>$types){
            foreach ($types as $type) {
                $itm = $this->getMinParams($code, $type);
                $itm['active'] = in_array($itm['code'], $activeCodes) ? 'Y' : 'N';
                $itm['robot'] = substr($itm['code'],-2)=='_r' ? 'Y' : 'N';
                $items[] = $itm;
            }
        }

        $tracker?->addCount()?->calcHitTime();
        if($tracker)
            \Awz\BxApiStats\Tracker::saveStat($domain,$app);

        return array(
            'items'=>$items
        );
    }



    public function checkAction(string $sign, string $salt, string $key = '')
    {

        try{
            $signer = new \Bitrix\Main\Security\Sign\Signer();
            if($key) $signer->setKey($key);
            $signVal = base64_decode($signer->unsign($sign, $salt));
        }catch (\Exception $e){
            //$this->addError(new Error($e->getMessage()));
        }

        if($signVal){
            return $signVal;
        }
        $this->addError(new Error("bad signature"));
    }

    public function statAction(string $domain, string $app){

        $logger = $this->getLogger();
        $logger?->debug(
            "[fullactivity.stat]\n{date}\n{args}\n",
            ['args' => func_get_args()]
        );
        if(!$domain || !$app){
            $this->addError(
                new Error('Ошибка в параметрах запроса', 100)
            );
            return null;
        }
        $tracker = null;
        if(Loader::includeModule('awz.bxapistats')){
            $tracker = \Awz\BxApiStats\Tracker::getInstance();
            $tracker->setPortal($domain)->setAppId($app);
        }
        if(!$this->checkRequire(['user', 'bxuser', 'bxadmin'])){
            $this->addError(
                new Error('Авторизация не найдена', 105)
            );
            return null;
        }

        $r = WorkAppLogTable::getList([
            'select'=>['*'],
            'filter'=>[
                '=APP'=>$app,
                '=PORTAL'=>$domain,
                '>=DATE_ADD'=>\Bitrix\Main\Type\DateTime::createFromTimestamp(
                    strtotime(date('d.m.Y', strtotime('-1 day')))
                )
            ]
        ]);
        $chart = [
            'today'=>[],
            'today_cnt'=>0,
            'today_err'=>0,
            'today_work'=>0,
            'today_all_time'=>0,
            'today_avg_time'=>0,

            'yesterday'=>[],
            'yesterday_cnt'=>0,
            'yesterday_err'=>0,
            'yesterday_work'=>0,
            'yesterday_all_time'=>0,
            'yesterday_avg_time'=>0,
        ];
        $cntTypes = [];
        $cntTypes2 = [];
        foreach($chart as $k=>&$v){
            if(!in_array($k, ['today','yesterday']))
                continue;
            $v = [];
            for($i=100;$i<124;$i++){
                $hour = mb_substr((string)$i,1);
                $v[(int)$hour] = [
                    "hour"=>$hour,
                    "vip"=>0,
                    "higt"=>0,
                    "medium"=>0,
                    "low"=>0,
                    "ban"=>0
                ];
            }
        }
        unset($v);
        while($data = $r->fetch()){
            $day = strtotime($data['DATE_ADD'])<strtotime(date('d.m.Y')) ? "yesterday" : "today";
            $hour = date("H", strtotime($data['DATE_ADD']));
            $type = 'medium';

            $code = substr($data['PARAMS']['type'],-2)=='_r' ? substr($data['PARAMS']['type'],0,-2) : $data['PARAMS']['type'];
            if(!$code) continue;

            if(!isset($cntTypes[$code])) $cntTypes[$code] = 0;
            if(!isset($cntTypes2[$code])) $cntTypes2[$code] = 0;
            $cntTypes[$code] += 1;
            if(isset($data['PARAMS']['exists_time'])){
                if(isset($data['PARAMS']['priority']))
                    $type = $data['PARAMS']['priority'];
                $chart[$day][(int)$hour][$type] += 1;
                if(!in_array($data['PARAMS']['type'], ['awz_timeout'])){
                    $chart[$day.'_cnt'] += 1;
                    $exTime = ($data['PARAMS']['exists_time'] - strtotime($data['DATE_ADD']));
                    $chart[$day.'_all_time'] += round($exTime,3);
                    $cntTypes2[$code] += round($exTime,3);
                }
            }else{
                $chart[$day.'_work'] += 1;
            }
        }

        if($chart['today_cnt']){
            $chart['today_avg_time'] = $chart['today_all_time']/$chart['today_cnt'];
        }
        if($chart['yesterday_cnt']){
            $chart['yesterday_avg_time'] = $chart['yesterday_all_time']/$chart['yesterday_cnt'];
        }

        arsort($cntTypes);
        arsort($cntTypes2);

        $chart['stat1'] = [];
        $chart['stat2'] = [];

        $cn = 0;
        $cn2 = 0;
        foreach($cntTypes as $code=>$v){
            $cn+=1;
            if($cn>5){
                $cn2+=$v;
            }else{
                $chart['stat1'][]=['name'=>$code, 'value'=>$v];
            }
        }
        $chart['stat1'][] = ['name'=>'остальные', 'value'=>$cn2];

        $cn = 0;
        $cn2 = 0;
        foreach($cntTypes2 as $code=>$v){
            $cn+=1;
            if($cn>5) {
                $cn2+=$v;
            }else{
                $chart['stat2'][]=['name'=>$code, 'value'=>$v];
            }
        }
        $chart['stat2'][] = ['name'=>'остальные', 'value'=>$cn2];

        $tracker?->addCount()?->calcHitTime();
        if($tracker)
            \Awz\BxApiStats\Tracker::saveStat($domain,$app);

        return $chart;

    }

    public static function getMd(array $hookResult):string
    {
        if(!isset($hookResult['params']['PROPERTIES'])) return '';
        $robot = substr($hookResult['params']['CODE'], -2)=='_r' ? true : false;
        $md = '';
        if($robot){
            $md .= "##Входные параметры робота"."\n";
        }else{
            $md .= "##Входные параметры действия бизнес процесса"."\n";
        }
        $md .= '| Параметр | Тип | Множественный | Обязательный |'."\n";
        $md .= '|---|---|---|---|'."\n";
        foreach($hookResult['params']['PROPERTIES'] as $code=>$prop){
            $prop['Name'] = str_replace('|','\|',$prop['Name']);
            $md .= '| `'.$code.'` '.$prop['Name'].' | `'.$prop['Type'].'` | '.($prop['Multiple']=='Y'?"Да":"Нет").' | '.($prop['Required']=='Y'?"Да":"Нет").' |'."\n";
        }
        if(empty($hookResult['params']['PROPERTIES'])){
            $md .= "Не содержит входных параметров"."\n";
        }
        $md .= "\n";
        if($robot){
            $md .= "##Результат выполнения робота"."\n";
        }else{
            $md .= "##Результат действия бизнес процесса"."\n";
        }
        $md .= '| Параметр | Тип | Множественный |'."\n";
        $md .= '|---|---|---|'."\n";
        foreach($hookResult['params']['RETURN_PROPERTIES'] as $code=>$prop){
            $prop['Name']['ru'] = str_replace('|','\|',$prop['Name']['ru']);
            $md .= '| `'.$code.'` '.$prop['Name']['ru'].' | `'.$prop['Type'].'` | '.($prop['Multiple']=='Y'?"Да":"Нет").' |'."\n";
        }
        if(empty($hookResult['params']['RETURN_PROPERTIES'])){
            $md .= "Не возвращает данных"."\n";
        }
        return $md;
    }
}