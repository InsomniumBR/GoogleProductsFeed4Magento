<?php

class SmartIT_GoogleProductsFeed_IndexController extends Mage_Core_Controller_Front_Action {
	
	private $oProducts;
	private $oProductIds;
	private $sFileName = 'products.xml';
	private $xmlWriter;
	private $oConfig;
	private $aBadChars = array('"',"\r\n","\n","\r","\t");
	private $aReplaceChars = array(""," "," "," ",""); 

	private $aConfigPaths = array (	'googleproductsfeed/general/enabled',
									'googleproductsfeed/general/filename',
									'googleproductsfeed/general/sitetitle',
									'googleproductsfeed/general/sitedescription',
									'googleproductsfeed/general/siteurl',									
									'googleproductsfeed/general/currencycode',
									'googleproductsfeed/general/imgresizewidth',
									'googleproductsfeed/general/imgresizeheight',
									'googleproductsfeed/general/productcondition',
									'googleproductsfeed/general/producttype',
									'googleproductsfeed/general/gtin',
									'googleproductsfeed/general/mpn',
									'googleproductsfeed/general/googleproductcategory',									
									'googleproductsfeed/general/urlparam'									
	);

	private $aDataMap = array(	'g:id' 						=> 'id',
								'title' 					=> 'title',
								'link' 						=> 'link',								
								'description' 				=> 'description',
								'g:condition'				=> 'condition',
								'g:price'					=> 'special_price',
								'g:availability'			=> 'availability',
								'g:image_link' 				=> 'image_link',
								'g:brand' 					=> 'brand',
								'g:google_product_category'	=> 'google_product_category',
								'g:product_type' 			=> 'product_type',
								'g:mpn' 					=> 'mpn',
								'g:gtin' 					=> 'gtin'
								);



	public function indexAction() {
		set_time_limit(3600);

		$this->getConfig();
		
		if($this->oConfig->general->enabled == 1) {		

			if($this->oConfig->general->filename)
				$this->sFileName = $this->oConfig->general->filename;
			
			if($this->needToRegenerate())
			{
				$this->deleteFile();
				$this->generateXml();
			}

			$this->returnXml();
		}
	}

	private function needToRegenerate() {
		return !file_exists($this->sFileName) || date('d', filemtime($this->sFileName)) != date('d');
	}

	private function deleteFile()
	{
		if(file_exists($this->sFileName)) unlink($this->sFileName);
	}

	private function returnXml()
	{
	    header('Content-Description: File Transfer');
		header('Content-Type: text/xml');
		header('Content-Disposition: attachment; filename="'.basename($this->sFileName).'"');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . filesize($this->sFileName));
		readfile($this->sFileName);
	}

	private function generateXml() {

		$this->loadProducts();

		$this->xmlWriter = new XMLWriter();
		$this->xmlWriter->openMemory();
		
		$this->xmlWriter->startElement('rss');
			$this->xmlWriter->writeAttributeNS('xmlns','g','http://www.w3.org/2000/xmlns/','http://base.google.com/ns/1.0');
			$this->xmlWriter->writeAttribute('version','2.0');
  
			$this->xmlWriter->startElement('channel');

				$this->writeElement('title', $this->oConfig->general->sitetitle, true);
				$this->writeElement('link', $this->oConfig->general->siteurl, true);
				$this->writeElement('description', $this->oConfig->general->sitedescription, true);

				//DEBUG
				// $z=0;
				
				foreach($this->oProductIds as $i) {
					// $z += 1;

					//if($i > 926 || $i < 870) continue;
					//if($i != 1020) continue;
					
					$product = Mage::getModel('catalog/product');
					$product->getResource()->load($product, $i);
					$this->writeItem($product);

					file_put_contents($this->sFileName, $this->xmlWriter->flush(true), FILE_APPEND);
					
					$product = NULL;
						
					// if($z == 100) break;
				}

			$this->xmlWriter->endElement(); //channel
			
		$this->xmlWriter->endElement(); //rss
		
		// Final flush to make sure we haven't missed anything
		file_put_contents($this->sFileName, $this->xmlWriter->flush(true), FILE_APPEND);
	}

	private function writeItem($product)
	{
		$this->xmlWriter->startElement('item');

		$aData = $this->getProductData($product);
		
		foreach($this->aDataMap as $sElemName => $sLookUpValue) {
			$sValue = $aData[$sLookUpValue];
			
			if($sValue)
			{
				if($sLookUpValue == 'link' && $this->oConfig->general->urlparam != '') {
					$sValue = $this->addUrlParam($sValue);
				}
				
				$this->writeElement($sElemName, $sValue, !is_numeric($sValue));
			}
		}
		
		$aData = NULL;

		$this->xmlWriter->endElement(); // item
	}
	
	private function writeElement($name, $value, $CData)
	{
			if($CData)
			{
				$this->xmlWriter->startElement($name);
				$this->xmlWriter->writeCData($value);
				$this->xmlWriter->endElement();
			}
			else
				$this->xmlWriter->writeElement($name, $value);
	}

	private function loadProducts() {
		$this->oProducts = Mage::getModel('catalog/product')->getCollection();
		$this->oProducts->addAttributeToFilter('status', 1);//enabled
		$this->oProducts->addAttributeToFilter('visibility', 4);//catalog, search
        //$this->oProducts->addAttributeToSelect('sku');
		// $this->oProducts->addAttributeToSelect(
			// array('name'
				// , 'short_description'
				// , 'description'
				// , 'price'
				// , 'image'
				// , 'status'
				// , 'manufacturer'
				// , 'url_path'), 'inner');

		$this->oProducts->addAttributeToSelect('*');
		$this->oProductIds = $this->oProducts->getAllIds();		
	}

	private function getProductData($oProduct) {

	    $aData = array();	
	    $aData['id']=$this->prepareData($oProduct->getId(), 50);
		$aData['title']=$this->prepareData($oProduct->getName(), 150);
	    $aData['description']=$this->prepareData(strip_tags($oProduct->getDescription()), 5000);
		$aData['link']=$this->prepareData($oProduct->getProductUrl(), 2000);
		
		$product_stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($oProduct->getId());
	
		$aData['availability'] = ($product_stock['is_in_stock'] == 0) ? 'out of stock' : 'in stock';

		$aData['price']=number_format($oProduct->getPrice(),2) . " " . $this->oConfig->general->currencycode;
		$aData['special_price']=number_format($oProduct->getSpecialPrice(),2) . " " . $this->oConfig->general->currencycode;

	    if(!$oProduct->getSpecialPrice()) {
	    	$aData['special_price'] = $aData['price'];
	    }

		$aData['brand']=$this->prepareData($oProduct->getResource()->getAttribute('brand')->getFrontend()->getValue($oProduct), 70);

		if($this->oConfig->general->imgresizewidth > 0 && $this->oConfig->general->imgresizeheight > 0)
		{
			$aData['image_link']= (string) Mage::helper('catalog/image')
				->init($oProduct, 'image')
				->resize($this->oConfig->general->imgresizewidth,$this->oConfig->general->imgresizeheight);
		}
		else
		{
			$aData['image_link']= Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA).'catalog/product'.$oProduct->getImage();
		}
		
		$aData['image_link'] = $this->prepareData($aData['image_link'], 2000);

		$conditionValue = $this->oConfig->general->productcondition;
		if($oProduct->getResource()->getAttribute($conditionValue))
			$aData['condition']=$oProduct->getResource()->getAttribute($conditionValue);
		else
			$aData['condition']=$conditionValue;
		
		$googleProductCategoryValue = $this->oConfig->general->googleproductcategory;
		if($oProduct->getResource()->getAttribute($googleProductCategoryValue))
			$aData['google_product_category']=$oProduct->getResource()->getAttribute($googleProductCategoryValue)->getFrontend()->getValue($oProduct);
		else
			$aData['google_product_category']=$googleProductCategoryValue;

		$productTypeValue = $this->oConfig->general->producttype;
		if($oProduct->getResource()->getAttribute($productTypeValue))
			$aData['product_type']=$oProduct->getResource()->getAttribute($productTypeValue)->getFrontend()->getValue($oProduct);
		else
		{
			if($productTypeValue) $aData['product_type']=$productTypeValue;
			else $aData['product_type']=$this->infereCategory($oProduct);
		}
		
		$gtinValue = $this->oConfig->general->gtin;
		if($gtinValue)
		{
			if($oProduct->getResource()->getAttribute($gtinValue))
				$aData['gtin']=$oProduct->getResource()->getAttribute($gtinValue)->getFrontend()->getValue($oProduct);
			else
				$aData['gtin']=$gtinValue;
		}

		$mpnValue = $this->oConfig->general->mpn;
		if($mpnValue)
		{
			if($oProduct->getResource()->getAttribute($mpnValue))
				$aData['mpn']=$oProduct->getResource()->getAttribute($mpnValue)->getFrontend()->getValue($oProduct);
			else 
				$aData['mpn']=$mpnValue;					
		}

	    return $aData;
	}

	private function addUrlParam($sUrl) {
		if(!strstr($sUrl,'?')) {
			$sUrl .= '?'.str_replace('?','',$this->oConfig->general->urlparam);	
		} else {
			$sUrl .= '&'.str_replace('?','',$this->oConfig->general->urlparam);
		}		
		return $sUrl;		
	}

	private function getConfig() {
		$this->oConfig = new StdClass();
		foreach($this->aConfigPaths as $sPath) {
			$aParts = explode('/',$sPath);
			@$this->oConfig->$aParts[1]->$aParts[2] = Mage::getStoreConfig($sPath);
		}

		if(!is_numeric($this->oConfig->general->imgresizewidth)) {
			$this->oConfig->general->imgresizewidth = 0;
		}
		if(!is_numeric($this->oConfig->general->imgresizeheight)) {
			$this->oConfig->general->imgresizeheight = 0;
		}
	}

	private function prepareData($data, $limit)
	{
		$data = str_replace($this->aBadChars,$this->aReplaceChars,$data);
		if($limit) $data = substr($data, 0, $limit);
		return $data;
	}

	private function findSubCategory($category, $categories)
	{
		foreach($categories as $l => $c){
			if($c->getParentId() == $category->getId())
			{
				return ' > ' . $c->getName() .  $this->findSubCategory($c, $categories);
			}
		}
		return '';
	}
	
	private function infereCategory($oProduct) {

		$aIds = $oProduct->getCategoryIds();
		$categoriesByLevel = array();
		$catList = array();
		
		foreach($aIds as $iCategory){
			$oCategory = Mage::getModel('catalog/category')->load($iCategory);
			$categoriesByLevel[] = $oCategory;
	    }

		usort($categoriesByLevel, function($c1, $c2) {
			return $c1->getLevel() - $c2->getLevel();
		});

		$lowerLevel = reset($categoriesByLevel)->getLevel();
	
		foreach($categoriesByLevel as $l => $c){
			if($c->getLevel() == $lowerLevel)
			{
				$catList[] = $c->getName() . $this->findSubCategory($c, $categoriesByLevel);
			}
		}
		
		usort($catList, function($a, $b) {
			return strlen($b) - strlen($a);
		});
		
		return reset($catList);
	}
}