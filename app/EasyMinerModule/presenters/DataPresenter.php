<?php

namespace App\EasyMinerModule\Presenters;
use App\Libs\StringsHelper;
use App\Model\Data\Facades\DatabasesFacade;
use App\Model\Data\Facades\FileImportsFacade;
use App\Model\EasyMiner\Entities\Miner;
use App\Model\EasyMiner\Facades\DatasourcesFacade;
use App\Model\EasyMiner\Facades\MinersFacade;
use App\Model\EasyMiner\Facades\UsersFacade;
use Nette\Application\BadRequestException;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\Forms\Controls\SubmitButton;
use Nette\Forms\Controls\TextInput;
use Nette\Http\FileUpload;
use Nette\Utils\DateTime;

/**
 * Class DataPresenter - presenter pro práci s daty (import, zobrazování, smazání...)
 * @package App\EasyMinerModule\Presenters
 */
class DataPresenter extends BasePresenter{

  /** @var DatasourcesFacade $datasourcesFacade */
  private $datasourcesFacade;
  /** @var  MinersFacade $minersFacade */
  private $minersFacade;
  /** @var  FileImportsFacade $fileImportsFacade */
  private $fileImportsFacade;
  /** @var  DatabasesFacade $databasesFacade */
  private $databasesFacade;
  /** @var  UsersFacade $usersFacade */
  private $usersFacade;

  public function renderMapping($datasource){
    $this->template->datasource=$datasource;
    //TODO akce a rozhraní pro mapování na KnowledgeBase!!!
    echo 'MAPPING!!!';
    $this->terminate();
  }

  public function renderImportCsvDataPreview($file,$separator=',',$encoding='utf8',$enclosure='"',$escape='\\'){
    $this->layout='blank';
    $this->fileImportsFacade->changeFileEncoding($file,$encoding);
    $this->template->colsCount=$this->fileImportsFacade->getColsCountInCSV($file,$separator,$enclosure,$escape);
    $this->template->rows=$this->fileImportsFacade->getRowsFromCSV($file,20,$separator,$enclosure,$escape);
  }

  /**
   * Akce pro otevření existujícího mineru (adresa pro přesměrování je brána z konfigu
   * @param int $id
   * @throws BadRequestException
   */
  public function actionOpenMiner($id){
    try{
      $miner=$this->minersFacade->findMiner($id);
    }catch (\Exception $e){
      throw new BadRequestException($this->translate('Requested miner not found!'),404,$e);
    }

    $this->checkMinerAccess($miner);

    $url=$this->context->parameters['urls']['open_miner'];
    $this->redirectUrl(StringsHelper::replaceParams($url,array(':minerId'=>$id)));
  }

  /**
   * Akce pro vytvoření mineru nad konkrétním datovým zdrojem
   * @param int $datasource
   * @throws BadRequestException
   */
  public function renderNewMinerFromDatasource($datasource){
    try{
      $datasource=$this->datasourcesFacade->findDatasource($datasource);
    }catch (\Exception $e){
      throw new BadRequestException('Requested datasource was not found!',404,$e);
    }

    $this->checkDatasourceAccess($datasource);
    $this->template->datasource=$datasource;
    /** @var Form $form */
    $form=$this->getComponent('newMinerForm');
    $dateTime=new DateTime();
    $form->setDefaults(array('datasource'=>$datasource->datasourceId,'datasourceName'=>$datasource->dbName,'name'=>$datasource->dbName.' '.$dateTime->format('u')));
  }

  /**
   * Akce pro založení nového EasyMineru či otevření stávajícího
   */
  public function renderNewMiner(){
    $this->template->miners=$this->minersFacade->findMinersByUser($this->user->id);
    $this->template->datasources=$this->datasourcesFacade->findDatasourcesByUser($this->user->id);
  }

  /**
   * Akce pro import dat z nahraného souboru/externí DB
   * @param string $file = ''
   * @param string $type = ''
   * @param string $name=''
   */
  public function renderUploadData($file='',$type='',$name=''){
    if ($file && $type){
      //zobrazení nastavení importu (již máme nahraný soubor
      /** @var Form $form */
      $form=$this->getComponent('import'.$type.'Form');
      $defaultsArr=array('file'=>$file,'type'=>$type,'table'=>$this->databasesFacade->prepareNewTableName($name,false));

      if ($type=='Csv'){
        //detekce pravděpodobného oddělovače
        $separator=$this->fileImportsFacade->getCSVDelimitier($file);
        $defaultsArr['separator']=$separator;
        //připojení k DB pro zjištění názvu tabulky, který zatím není obsazen (dle typu preferované databáze)
        $csvColumnsCount=$this->fileImportsFacade->getColsCountInCSV($file,$separator);
        $databaseType=$this->databasesFacade->prefferedDatabaseType($csvColumnsCount);
        $newDatasource=$this->datasourcesFacade->prepareNewDatasourceForUser($this->usersFacade->findUser($this->user->id),$databaseType);
        $this->databasesFacade->openDatabase($newDatasource->getDbConnection());
        $defaultsArr['table']=$this->databasesFacade->checkTableExists($name);
      }else{
        //TODO další možnosti importu (ZIP soubor atd.)
      }

      if (!$form->isSubmitted()){
        $form->setDefaults($defaultsArr);
      }
      $this->template->importForm=$form;
    }
  }

  public function renderImportMysql(){
    throw new BadRequestException('Not implemented yet!');//TODO
  }

  /**
   * Akce pro smazání konkrétního mineru
   * @param int $id
   */
  public function renderDeleteMiner($id){
    $miner=$this->minersFacade->findMiner($id);
    $this->checkMinerAccess($miner);
    //TODO
  }

  /**
   * Akce pro vykreslení histogramu z hodnot konkrétního atributu
   * @param int $miner = null
   * @param string $attribute
   * @param string $layout = 'default'|'component'|'iframe'
   * @throws BadRequestException
   * @throws \Nette\Application\ApplicationException
   * @throws \Nette\Application\ForbiddenRequestException
   */
  public function renderAttributeHistogram($miner,$attribute, $layout='default'){
    if ($miner){
      try{
        $miner=$this->minersFacade->findMiner($miner);
      }catch (\Exception $e){
        throw new BadRequestException('Requested miner not specified!', 404, $e);
      }
      $this->checkMinerAccess($miner);
    }
    try{
      $datasource=$miner->getAttributesDatasource();
      $this->databasesFacade->openDatabase($datasource->getDbConnection());
      $this->template->attributeValuesStatistic=$this->databasesFacade->getColumnValuesStatistic($datasource->dbTable,$attribute);
    }catch (\Exception $e){
      throw new BadRequestException('Requested attribute not found!',500,$e);
    }
    if ($this->isAjax() || $layout=='component' || $layout=='iframe'){
      $this->layout='iframe';
      $this->template->layout=$layout;
    }
  }

  /**
   * Akce pro vykreslení histogramu z hodnot konkrétního sloupce v DB
   * @param int $datasource = null
   * @param int $miner = null
   * @param string $column
   * @param string $layout = 'default'|'component'|'iframe'
   * @throws BadRequestException
   * @throws \Nette\Application\ApplicationException
   * @throws \Nette\Application\ForbiddenRequestException
   */
  public function renderColumnHistogram($datasource=null, $miner=null ,$column, $layout='default'){
    if ($miner){
      $miner=$this->minersFacade->findMiner($miner);
      $this->checkMinerAccess($miner);
      $datasource=$miner->datasource;
    }elseif($datasource){
      $datasource=$this->datasourcesFacade->findDatasource($datasource);
      $this->checkDatasourceAccess($datasource);
    }
    if (!$datasource){
      throw new BadRequestException('Requested data not specified!');
    }

    $this->databasesFacade->openDatabase($datasource->getDbConnection());
    $this->template->dbColumnValuesStatistic=$this->databasesFacade->getColumnValuesStatistic($datasource->dbTable,$column);

    if ($this->isAjax() || $layout=='component' || $layout=='iframe'){
      $this->layout='iframe';
      $this->template->layout=$layout;
    }
  }


  #region componentUploadForm
  public function createComponentUploadForm(){
    $form = new Form();
    $form->setTranslator($this->translator);
    $form->addHidden('type','Csv');
    $file=$form->addUpload('file','CSV file:');
    $file->setRequired('Je nutné nahrát soubor pro import!')
      ->addRule(function($control){
        return ($control->value->ok);
      },'Chyba při uploadu souboru!');
    $form->addSubmit('submit','Upload file...')->onClick[]=array($this,'uploadFormSubmitted');
    $presenter=$this;
    $storno=$form->addSubmit('storno','storno');
    $storno->setValidationScope(array());
    $storno->onClick[]=function()use($presenter){
      $presenter->redirect('Data:newMiner');
    };
    return $form;
  }

  /**
   * Handler po nahrání souboru => uloží soubor do temp složky a přesměruje uživatele na formulář pro konfiguraci importu
   * @param SubmitButton $submitButton
   */
  public function uploadFormSubmitted(SubmitButton $submitButton){
    /** @var Form $form */
    $form=$submitButton->form;
    $values=$form->getValues();
    /** @var FileUpload $file */
    $file=$values['file'];
    $filename=$this->fileImportsFacade->getTempFilename();
    $file->move($this->fileImportsFacade->getFilePath($filename));
    $name=$file->getSanitizedName();
    $name=mb_substr($name,0,mb_strrpos($name,'.','utf8'));
    $name=str_replace('.','_',$name);
    $this->redirect('Data:uploadData',array('file'=>$filename,'type'=>$values['type'],'name'=>$name));
  }
  #endregion componentUploadForm

  #region componentNewMinerForm
  /**
   * @return Form
   */
  public function createComponentNewMinerForm() {
    $form = new Form();
    $form->setTranslator($this->translator);
    $form->addHidden('datasource');
    $minersFacade=$this->minersFacade;
    $currentUserId = $this->user->id;
    $form->addText('datasourceName','Datasource:')
      ->setAttribute('readonly');
    $form->addText('name', 'Miner name:')
      ->setRequired('Input the miner name!')
      ->setAttribute('autofocus')
      ->addRule(Form::MAX_LENGTH,'Max length of the table name is %s characters!',30)
      ->addRule(Form::MIN_LENGTH,'Max length of the table name is %s characters!',3)
      ->addRule(Form::PATTERN,'Table name can contain only letters, numbers and underscore and start with a letter!','/[a-zA-Z]\w+/')
      ->addRule(function(TextInput $control)use($currentUserId,$minersFacade){
        try{
          $miner=$minersFacade->findMinerByName($currentUserId,$control->value);
          if ($miner instanceof Miner){
            return false;
          }
        }catch (\Exception $e){/*chybu ignorujeme (nenalezený miner je OK)*/}
        return true;
      },'Miner with this name already exists!');

    $form->addSelect('type','Miner type:',Miner::getTypes())->setDefaultValue(Miner::DEFAULT_TYPE);

    $form->addSubmit('submit','Create miner...')->onClick[]=array($this,'newMinerFormSubmitted');
    $stornoButton=$form->addSubmit('storno','storno');
    $stornoButton->setValidationScope(array());
    $stornoButton->onClick[]=function(SubmitButton $button){
      /** @var Presenter $presenter */
      $presenter=$button->form->getParent();
      $presenter->redirect('Data:newMiner');
    };
    return $form;
  }

  public function newMinerFormSubmitted(SubmitButton $submitButton){
    /** @var Form $form */
    $form=$submitButton->form;
    $values=$form->getValues();
    $miner=new Miner();
    $miner->user=$this->usersFacade->findUser($this->user->id);
    $miner->name=$values['name'];
    $miner->created=new DateTime();
    $miner->type=$values['type'];
    $this->minersFacade->saveMiner($miner);
    $this->redirect('Data:openMiner',array('id'=>$miner->minerId));
  }
  #endregion componentNewMinerForm

  #region componentImportCsvForm
  /**
   * @return Form
   */
  public function createComponentImportCsvForm(){
    $form = new Form();
    $form->setTranslator($this->translator);
    $tableName=$form->addText('table','Table name:')
      ->setAttribute('class','normalWidth')
      ->setRequired('Input table name!');
    $presenter=$this;
    $tableName->addRule(Form::MAX_LENGTH,'Max length of the table name is %s characters!',30)
      ->addRule(Form::MIN_LENGTH,'Max length of the table name is %s characters!',3)
      ->addRule(Form::PATTERN,'Table name can contain only letters, numbers and underscore and start with a letter!','/[a-zA-Z]\w+/')
      ->addRule(function(TextInput $control)use($presenter){
        $formValues=$control->form->getValues(true);
        $csvColumnsCount=$presenter->fileImportsFacade->getColsCountInCSV($formValues['file'],$formValues['separator'],$formValues['enclosure'],$formValues['escapeCharacter']);
        $databaseType=$presenter->databasesFacade->prefferedDatabaseType($csvColumnsCount);
        $newDatasource=$presenter->datasourcesFacade->prepareNewDatasourceForUser($presenter->user->id,$databaseType);
        $presenter->databasesFacade->openDatabase($newDatasource->getDbConnection());
        return (!$presenter->databasesFacade->checkTableExists($control->value));
      },'Table with this name already exists!');

    $form->addSelect('separator','Separator:',array(
      ','=>'Comma (,)',
      ';'=>'Semicolon (;)',
      '|'=>'Vertical line (|)',
    ))->setRequired()
      ->setAttribute('class','normalWidth');

    $form->addSelect('encoding','Encoding:',array(
      'utf8'=>'UTF-8',
      'cp1250'=>'WIN 1250',
      'iso-8859-1'=>'ISO 8859-1',
    ))->setRequired()
      ->setAttribute('class','normalWidth');
    $file=$form->addHidden('file');
    $form->addHidden('type');
    $form->addText('enclosure','Enclosure:',1,1)->setDefaultValue('"');
    $form->addText('escape','Escape:',1,1)->setDefaultValue('\\');
    $form->addSubmit('submit','Import data into database...')->onClick[]=array($this,'importCsvFormSubmitted');
    $storno=$form->addSubmit('storno','storno');
    $storno->setValidationScope(array());
    $storno->onClick[]=function(SubmitButton $button)use($file){
      /** @var DataPresenter $presenter */
      $presenter=$button->form->getParent();
      $this->fileImportsFacade->deleteFile($file->value);
      $presenter->redirect('Data:newMiner');
    };
    return $form;
  }

  public function importCsvFormSubmitted(SubmitButton $submitButton){
    /** @var Form $form */
    $form=$submitButton->form;
    $values=$form->getValues();
    $user=$this->usersFacade->findUser($this->user->id);
    #region params
    $table=$values['table'];
    $separator=$values["separator"];
    $encoding=$values["encoding"];
    $file=$values["file"];
    $enclosure=$values["enclosure"];
    $escape=$values["escape"];
    #endregion

    $colsCount=$this->fileImportsFacade->getColsCountInCSV($file,$separator,$enclosure,$escape);
    $dbType=$this->databasesFacade->prefferedDatabaseType($colsCount);
    //připravení připojení k DB
    $datasource=$this->datasourcesFacade->prepareNewDatasourceForUser($user,$dbType);
    $this->fileImportsFacade->importCsvFile($file,$datasource->getDbConnection(),$table,$encoding,$separator,$enclosure,$escape);
    $datasource->dbTable=$table;
    //uložíme datasource
    $this->datasourcesFacade->saveDatasource($datasource);
    //smažeme dočasné soubory...
    $this->fileImportsFacade->deleteFile($file);
    $this->redirect('Data:mapping',array('datasource'=>$datasource));
  }
  #endregion importCsvForm

  #region injections
  /**
   * @param DatasourcesFacade $datasourcesFacade
   */
  public function injectDatasourcesFacade(DatasourcesFacade $datasourcesFacade){
    $this->datasourcesFacade=$datasourcesFacade;
  }

  /**
   * @param MinersFacade $minersFacade
   */
  public function injectMinersFacade(MinersFacade $minersFacade){
    $this->minersFacade=$minersFacade;
  }

  /**
   * @param FileImportsFacade $fileImportsFacade
   */
  public function injectFileImportsFacade(FileImportsFacade $fileImportsFacade){
    $this->fileImportsFacade=$fileImportsFacade;
  }

  /**
   * @param DatabasesFacade $databasesFacade
   */
  public function injectDatabasesFacade(DatabasesFacade $databasesFacade){
    $this->databasesFacade=$databasesFacade;
  }

  /**
   * @param UsersFacade $usersFacade
   */
  public function injectUsersFacade(UsersFacade $usersFacade){
    $this->usersFacade=$usersFacade;
  }
  #endregion


  public function startup(){
    parent::startup();
    if (!$this->user->isLoggedIn()){
      $this->flashMessage('For using of EasyMiner, you have to log in...','error');
      $this->redirect('User:login');
    }
  }
}