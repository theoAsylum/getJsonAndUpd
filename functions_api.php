<?
define('API_URL', 'https://test2-lk.isb.ru/ti3/apex.wi_api');
define('DEBUG_PATH', '/local/php_interface/api/.log/');

function sendRequestByAPI($XML_ID, $debug = false, $post = [], $API_URL = API_URL)
{
    $optionsHttpClient = array(
        "redirect" => true,
        "redirectMax" => 5,
        "waitResponse" => true,
        "socketTimeout" => 300,
        "streamTimeout" => 0,
        "version" => \Bitrix\Main\Web\HttpClient::HTTP_1_1,
        "proxyHost" => "",
        "proxyPort" => "",
        "proxyUser" => "",
        "proxyPassword" => "",
        "compress" => false,
        "charset" => "",
        "disableSslVerification" => true,
    );
    $fileName = $_SERVER['DOCUMENT_ROOT'] . DEBUG_PATH . $XML_ID . '-'.date('y-m-d_H-i-s').'.json';
    $arPost = [];
    if($post){
        $post['PHONE'] = str_replace([' ', '(', ')', '-', '+'], '', $post['PHONE']);
        if($post['NAME']) $arPost['app_usr_name'] = $post['NAME']; else $arPost['app_usr_name'] = $post['PHONE'];
        $arPost['app_usr_phone'] = $post['PHONE'];

        if($post['BRAND']){
            $res = CIBlockElement::GetList([], ['IBLOCK_ID' => FourPx\Helper::getIblockIdByCode('brands'), 'ID' => $post['BRAND'], 'ACTIVE' => 'Y', '!PROPERTY_API_ID' => false], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_API_ID']);
            while($arBrand = $res->GetNext(true, false)) {
                if($arBrand['NAME'] != 'BMW Motorrad'){
                    $arPost['brand_id'] = 'CAR/'.$arBrand['PROPERTY_API_ID_VALUE'];
                }else{
                    $arPost['brand_id'] = 'MOTORCYCLE/BMW';
                }
            }
        }
        $arPost['model_id '] = '';
        if($post['CENTER']) {
            $res = CIBlockElement::GetList([], ['IBLOCK_ID' => FourPx\Helper::getIblockIdByCode('centers'), 'ID' => $post['CENTER'], 'ACTIVE' => 'Y', '!PROPERTY_API_ID' => false], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_API_ID']);
            while($arCenter = $res->GetNext(true, false)) {
                $arPost['wi_pt_dc_id'] = $arCenter['PROPERTY_API_ID_VALUE'];
            }
        }
        if($post['SERVICE']) {
            $res = CIBlockElement::GetList([], ['IBLOCK_ID' => FourPx\Helper::getIblockIdByCode('services'), 'ID' => $post['SERVICE'], 'ACTIVE' => 'Y', '!PROPERTY_API_ID' => false], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_API_ID']);
            while($arService = $res->GetNext(true, false)) {
                $arPost['serv_type_id'] = $arService['PROPERTY_API_ID_VALUE'];
            }
        }
        if(!$arPost['serv_type_id']) $arPost['serv_type_id'] = '8';
        $arPost['datetime'] = ''/*gmdate('d.m.Y h:i')*/;
        $arPost['mc_id'] = '0';
        $arPost['p_use_slot'] = '';
        $arPost['pkg_ids'] = '';
        $arPost['pkg_comms'] = '';
        $arPost['comms'] = '';
        $arPost['p_src_name'] = 'Сайт .ru';
        $arPost['pn'] = '';
        $arPost['pv'] = '';
    }

    //if(!file_exists($fileName)) {

    $httpClient = new \Bitrix\Main\Web\HttpClient($optionsHttpClient);
    //$httpClient->setHeader('Content-Type', 'application/json', true);
    $response = $httpClient->post($API_URL . '.' . $XML_ID, $arPost);
    $arImport = [];
    $arImport["Result"] = json_decode($httpClient->getResult());
    if ($debug) {
        $arImport['Response'] = $response;
        $arImport["Status"] = $httpClient->getStatus();
        $arImport["Errors"] = $httpClient->getError();
        $arImport["Headers"] = var_export($httpClient->getHeaders(), true);
        if ($post) {
            $arImport["Post"] = $arPost;
            $arImport["Post_before"] = $post;
        }
        file_put_contents($fileName, var_export($arImport, true));
    }

    if ($httpClient->getStatus() == 200) {
        if($arPost && $arImport["Result"]->status == 1){
            return true;
        }elseif($arPost && $arImport["Result"]->status == 0){
            CEvent::Send("CREATE_RL_ERROR","s1", $arImport, "N");
            file_put_contents($fileName, var_export($arImport, true));
            return false;
        }else{
            return $httpClient->getResult();
        }
    } else {
        if($arPost){
            CEvent::Send("CREATE_RL_ERROR","s1", $arImport, "N");
            file_put_contents($fileName, var_export($arImport, true));
        }
        return false;
    }
    /*}else{
        return file_get_contents($fileName);
    }*/
}

function updateServicesByAPI($debug = false)
{
    $result = sendRequestByAPI('get_Service_Types', $debug);
    $ServicesIblockId = FourPx\Helper::getIblockIdByCode('services');

    if($result){
        if (!CModule::IncludeModule("iblock")) die('Error Include Module «IBlock»');

        $result = json_decode($result);
        $el = new CIBlockElement;

        $arServices = [];
        $res = CIBlockElement::GetList([], ['IBLOCK_ID' => $ServicesIblockId, 'ACTIVE' => 'Y'], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_API_ID']);
        while($arService = $res->GetNext(true, false)){
            $arServices[$arService['NAME']] = $arService;
        }
        $i = 0;
        $arIDs = [];
        foreach ($result as $service) {
            $id_service_api = $service->serv_type_id;
            $name_service_api = $service->serv_type_name;
            if($arServices[$name_service_api] && $arServices[$name_service_api]['PROPERTY_API_ID_VALUE'] != $id_service_api){
                $i++;
                $arIDs[] = $arServices[$name_service_api]['ID'];
                $el->SetPropertyValuesEx($arServices[$name_service_api]['ID'], $ServicesIblockId, ["API_ID" => $id_service_api]);
            }
        }

        if($i){
            $result_txt = 'Обновлено услуг: '.$i.' (ID: '.implode(' ,', $arIDs).')';
        }else{
            $result_txt = 'Услуги не обновлены';
        }
        return $result_txt;
    }else{
        return 'Ошибка запроса';
    }
}

function updateBrandsAndModelsByAPI($BrandsIblockId = 1, $ModelsIblockId = 22, $debug = false)
{
    $result = sendRequestByAPI('e_g_car_ft', $debug);
    $BrandsIblockId = FourPx\Helper::getIblockIdByCode('brands');
    $ModelsIblockId = FourPx\Helper::getIblockIdByCode('models');

    if($result){
        if (!CModule::IncludeModule("iblock")) die('Error Include Module «IBlock»');

        $result = json_decode($result);
        $el = new CIBlockElement;
        $type_level = $result->TYPE_LEVEL;

        $arBrands = [];
        $res = CIBlockElement::GetList([], ['IBLOCK_ID' => $BrandsIblockId, 'ACTIVE' => 'Y'], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_API_ID']);
        while($arBrand = $res->GetNext(true, false)){
            $arBrands[strtolower($arBrand['NAME'])] = $arBrand;
            $arBrandsId[$arBrand['ID']] = strtolower($arBrand['NAME']);
        }

        $arModels = [];
        $res = CIBlockElement::GetList([], ['IBLOCK_ID' => $ModelsIblockId, 'ACTIVE' => 'Y'], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_API_ID', 'PROPERTY_BRAND_VALUE']);
        while($arModel = $res->GetNext(true, false)){
            $arModels[strtolower($arModel['NAME'])] = $arModel;
            $arModels[strtolower($arModel['NAME'])]['MARK'] = $arBrandsId[$arModel['PROPERTY_BRAND_VALUE']];
        }

        $i = 0;
        $y = 0;
        $arIDsBrands = [];
        $arIDsModels = [];

        foreach ($type_level as $types){
            if($types->id != 'CAR') continue;
            $brands = $types->MARK_LEVEL;
            foreach($brands as $brand) {
                $id_brand_api = $brand->id;
                $name_brand_api = strtolower($brand->name);

                if($arBrands[$name_brand_api] && $arBrands[$name_brand_api]['PROPERTY_API_ID_VALUE'] != $id_brand_api){
                    $i++;
                    $arIDsBrands[] = $arBrands[$name_brand_api]['ID'];
                    $el->SetPropertyValuesEx($arBrands[$name_brand_api]['ID'], $BrandsIblockId, ["API_ID" => $id_brand_api]);
                }

                $models = $brand->MODEL_LEVEL;
                foreach($models as $model){
                    $id_model_api = $model->id;
                    $name_model_api = strtolower($model->name);

                    if($arModels[$name_model_api] && $arModels[$name_model_api]['MARK'] == $name_brand_api && $arModels[$name_model_api]['PROPERTY_API_ID_VALUE'] != $id_model_api){
                        $y++;
                        $arIDsModels[] = $arModels[$name_model_api]['ID'];
                        $el->SetPropertyValuesEx($arModels[$name_model_api]['ID'], $ModelsIblockId, ["API_ID" => $id_model_api]);
                    }
                }
            }
        }

        $result_txt = '';
        if($i){
            $result_txt = 'Обновлено марок: '.$i.' (ID: '.implode(' ,', $arIDsBrands).')<br>';
        }else{
            $result_txt = 'Марки не обновлены<br>';
        }
        if($y){
            $result_txt .= 'Обновлено моделей: '.$y.' (ID: '.implode(' ,', $arIDsModels).')<br>';
        }else{
            $result_txt .= 'Модели не обновлены<br>';
        }
        return $result_txt;
    }else{
        return 'Ошибка запроса';
    }
}

function updateCentersByAPI($debug = false)
{
    $result = sendRequestByAPI('get_dealers', $debug);
    $CentersIblockId = FourPx\Helper::getIblockIdByCode('centers');

    if($result){
        if (!CModule::IncludeModule("iblock")) die('Error Include Module «IBlock»');

        $result = json_decode($result);
        $el = new CIBlockElement;

        $arCenters = [];
        $res = CIBlockElement::GetList([], ['IBLOCK_ID' => $CentersIblockId, 'ACTIVE' => 'Y'], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_API_ID']);
        while($arCenter = $res->GetNext(true, false)){
            $arCenters[$arCenter['NAME']] = $arCenter;
        }
        $i = 0;
        $arIDs = [];
        foreach ($result as $center) {
            $id_center_api = $center->dc_id;
            $name_center_api = $center->name;
            if($arCenters[$name_center_api] && $arCenters[$name_center_api]['PROPERTY_API_ID_VALUE'] != $id_center_api){
                $i++;
                $arIDs[] = $arCenters[$name_center_api]['ID'];
                $el->SetPropertyValuesEx($arCenters[$name_center_api]['ID'], $CentersIblockId, ["API_ID" => $id_center_api]);
            }
        }

        if($i){
            $result_txt = 'Обновлено СЦ: '.$i.' (ID: '.implode(' ,', $arIDs).')';
        }else{
            $result_txt = 'СЦ не обновлены';
        }
        return $result_txt;
    }else{
        return 'Ошибка запроса';
    }
}

function sendEntryByAPI($debug = false, $post = [])
{
    if($post){
        $postData = [];
        if($post['BRAND']){
            $postData['brand_id'] = 'CAR/';
            $res = CIBlockElement::GetList([], ['IBLOCK_ID' => FourPx\Helper::getIblockIdByCode('brands'), 'ID' => $post['BRAND'], 'ACTIVE' => 'Y', '!PROPERTY_API_ID' => false], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_API_ID']);
            if($arBrand = $res->GetNext(true, false)){
                $postData['brand_id'] .= $arBrand['PROPERTY_API_ID_VALUE'];
            }
        }
        if($post['CENTER']){
            $res = CIBlockElement::GetList([], ['IBLOCK_ID' => FourPx\Helper::getIblockIdByCode('centers'), 'ID' => $post['BRAND'], 'ACTIVE' => 'Y', '!PROPERTY_API_ID' => false], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_API_ID']);
            if($arCenter = $res->GetNext(true, false)){
                $postData['wi_pt_dc_id'] = $arCenter['PROPERTY_API_ID_VALUE'];
            }
        }
        if($post['SERVICE']){
            $res = CIBlockElement::GetList([], ['IBLOCK_ID' => FourPx\Helper::getIblockIdByCode('services'), 'ID' => $post['BRAND'], 'ACTIVE' => 'Y', '!PROPERTY_API_ID' => false], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_API_ID']);
            if($arService = $res->GetNext(true, false)){
                $postData['serv_type_id'] = $arService['PROPERTY_API_ID_VALUE'];
            }else{
                $postData['serv_type_id'] = 8;
            }
        }

        /*if($post['MODEL']){$post['model_id '] = '';}*/

        $post['app_usr_name'] = $post['PHONE'];
        $post['app_usr_phone'] = $post['PHONE'];
        //$post['datetime'] = ''/*gmdate('d.m.Y h:i')*/;
        $post['mc_id'] = '0';
        //$post['p_use_slot'] = '';
        //$post['pkg_ids'] = '';
        //$post['pkg_comms'] = '';
        //$post['comms'] = '';
        $post['p_src_name'] = 'Сайт .ru';
        //$post['pn'] = '';
        //$post['pv'] = '';

        $result = sendRequestByAPI('create_Service_Entry', $debug, $postData);
        if($result->status != 1){
            file_put_contents($_SERVER['DOCUMENT_ROOT'].'/local/.log/add_RLbyAPI'.date('y-m-d_H-i-s').'.log', var_export(['post'=>$post,'response'=>$result->error], true));
            return false;
        }else{
            return true;
        }
    }else{
        return false;
    }
}