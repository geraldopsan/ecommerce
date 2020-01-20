<?php 

namespace Hcode\Model;

use \Hcode\Db\Sql;
use \Hcode\Model;
use \Hcode\Mailer;
use \Hcode\Model\User;


class Cart extends Model
{
	
	const SESSION = "Cart";

	const SESSION_ERROR = "CartError";

	public static function getFromSession(){

		$cart = new Cart();

		if(isset($_SESSION[Cart::SESSION]) && (int)$_SESSION[Cart::SESSION]['idcart'] > 0)
		{

			$cart->get((int)$_SESSION[Cart::SESSION]['idcart']);
		}
		else
		{

			$cart->getFromSessionID();

			if (!(int)$cart->getidcart() >0) {

				$data = [

					'dessessionid'=>session_id(),
				];

				if (User::checkLogin(false)){

					$user = User::getFromSession();

					$data['iduser'] = $user->getiduser();
				}

				$cart->setData($data);

				$cart->save();

				$cart->setToSession();
			}
		}

		return $cart;
	}

	public function setToSession(){

		$_SESSION[Cart::SESSION] = $this->getValues();
	}

	public function getFromSessionID(){

		$sql = new Sql();

		$results = $sql->select("SELECT * FROM tb_carts WHERE dessessionid = :dessessionid", [

			':dessessionid'=>session_id()
		]);

		if(count($results) > 0){

			$this->setData($results[0]);
		}
	}		


	public function get(int $idcart){

		$sql = new Sql();

		$results = $sql->select("SELECT * FROM tb_carts WHERE idcart = :idcart", [

			':idcart'=>$idcart
		]);

		if (count($results)>0){

			$this->setData($results[0]);
		}
		
	}

	public function save()
	{
		
		$sql = new Sql();

		$results = $sql->select("CALL sp_carts_save(:idcart, :dessessionid, :iduser, :deszipcode, :vlfreight, :nrdays)", [

			':idcart'=>$this->getidcart(),
			':dessessionid'=>$this->getdessessionid(),
			':iduser'=>$this->getiduser(),
			':deszipcode'=>$this->getdeszipcode(),
			':vlfreight'=>$this->getvlfreight(),
			':nrdays'=>$this->getnrdays()
		]);

		$this->setData($results[0]);
	}

	public function addProduct(Product $product){

		$sql = new Sql();

		$sql->query("INSERT INTO tb_cartsproducts (idcart, idproduct) VALUES (:idcart , :idproduct)", [

			':idcart'=>$this->getidcart(),
			'idproduct'=>$product->getidproduct()
		]);

		$this->getCalculateTotal();
	}

	public function removeProduct(Product $product, $all = false){

		$sql = new Sql();

		if($all) {

			$sql->query("UPDATE tb_cartsproducts SET dtremoved = NOW() WHERE idcart = :idcart AND idproduct = :idproduct AND dtremoved IS NULL ",[

				':idcart'=>$this->getidcart(),
				':idproduct'=>$product->getidproduct()
			]);
		} else
		{

			$sql->query("UPDATE tb_cartsproducts SET dtremoved = NOW() WHERE idcart = :idcart AND idproduct = :idproduct AND dtremoved IS NULL LIMIT 1",[

				'idcart'=>$this->getidcart(),
				'idproduct'=>$product->getidproduct()
			]);
		}

		$this->getCalculateTotal();
	}

	public function getProducts(){

		$sql = new Sql();

		$rows = $sql->select("
			SELECT b.idproduct, b.desproduct, b.vlprice, b.vlwidth, b.vlheight, b.vllength, b.vlweight, b.desurl, COUNT(*) AS nrqtd, SUM(b.vlprice) AS vltotal 
			FROM tb_cartsproducts a 
			INNER JOIN tb_products b ON a.idproduct = b.idproduct 
			WHERE a.idcart = :idcart and a.dtremoved IS NULL 
			GROUP BY b.idproduct, b.desproduct, b.vlprice, b.vlwidth, b.vlheight, b.vllength, b.vlweight, b.desurl 
			ORDER BY b.desproduct",[

				':idcart'=>$this->getidcart()
		]);

		return Product::checkList($rows);
	}

	public function getProductsTotals()
	{
		$sql = new Sql();

		$results = $sql->select("
			SELECT SUM(vlprice) AS vlprice, SUM(vlwidth) AS vlwidth, SUM(vlheight) AS vlheight, SUM(vllength) AS vllength, SUM(vlweight) AS vlweight, COUNT(*) as nrqtd
			FROM tb_products a
			INNER JOIN tb_cartsproducts b ON a.idproduct = b.idproduct
			WHERE b.idcart = :idcart AND dtremoved IS NULL;", [
				':idcart'=>$this->getidcart()
			]);
		if (count($results) > 0){

			return $results[0];
		} else {
			return [];
		}
	}

	public function setFreight($numberzipcode)
	{

		$numberzipcode = str_replace('-', '', $numberzipcode);

		$totalsproducts = $this->getProductsTotals();

		if($totalsproducts['nrqtd'] > 0){

			if ($totalsproducts['vlheight'] < 2) $totalsproducts['vlheight'] = 2;
			if ($totalsproducts['vllength'] < 16) $totalsproducts['vllength'] = 16;
			if ($totalsproducts['vlwidth'] < 11) $totalsproducts['vlwidth'] = 11;
			
			$qs = http_build_query([

				'nCdEmpresa'=>'',
				'sDsSenha'=>'',
				'nCdServico'=>'04510',
				'sCepOrigem'=>'70715900',
				'sCepDestino'=>$numberzipcode,
				'nVlPeso'=>$totalsproducts['vlweight'],
				'nCdFormato'=>'1',
				'nVlComprimento'=>$totalsproducts['vllength'],
				'nVlAltura'=>$totalsproducts['vlheight'],
				'nVlLargura'=>$totalsproducts['vlwidth'],
				'nVlDiametro'=>'5',
				'sCdMaoPropria'=>'S',
				'nVlValorDeclarado'=>$totalsproducts['vlprice'],
				'sCdAvisoRecebimento'=>'S'
			]);
			
			$xml = simplexml_load_file("http://ws.correios.com.br/calculador/CalcPrecoPrazo.asmx/CalcPrecoPrazo?".$qs);

			$resultscarts = $xml->Servicos->cServico;

			if ($resultscarts->MsgErro != '') {
				
				Cart::setMessageError($resultscarts->MsgErro);
			}else{

				Cart::clearMessageError();
			}

			$this->setnrdays($resultscarts->PrazoEntrega);
			
			$this->setvlfreight(Cart::formatValueToDecimal($resultscarts->Valor));
			
			$this->setdeszipcode($numberzipcode);

			$this->save();

			return $resultscarts;
		} else {


		}
	}

	public static function formatValueToDecimal($value):float
	{

		$value = str_replace('.', '', $value);
		return str_replace(',', '.', $value);
	}

	public static function setMessageError($messageerror)
	{

		$_SESSION[Cart::SESSION_ERROR] = $messageerror;
	}

	public static function getMessageError()
	{

		$messagegeterror = (isset($_SESSION[Cart::SESSION_ERROR])) ? $_SESSION[Cart::SESSION_ERROR] : " ";

		Cart::clearMessageError();

		return $messagegeterror;
	}

	public static function clearMessageError()
	{

		$_SESSION[Cart::SESSION_ERROR] = NULL;
	}

	public function updateFreight()
	{

		if ($this->getdeszipcode() != '') {

			$this->setFreight($this->getdeszipcode());
		}
	}

	public function getValues()
	{

		$this->getCalculateTotal();

		return parent::getValues();
	}

	public function getCalculateTotal()
	{

		$this->updateFreight();

		$totals = $this->getProductsTotals();

		$this->setvlsubtotal($totals['vlprice']);
		
		$this->setvltotal($totals['vlprice'] + (float)$this->getvlfreight());
	}
}
?>