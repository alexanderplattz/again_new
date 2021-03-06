<?php
class UploadField extends Field
{
	public $limit;
	public $path;

	protected $sessionKey;
	protected $accepts;
	protected $acceptsMask;

	private $updateURL;

	protected static function storagePath()
	{
		return J_APPPATH . "storage" . DS;
	}

	protected static function storageRoot()
	{
		return URL::root(false) . "app/storage/";
	}

	protected static function tmpPath()
	{
		$path = J_APPPATH . "storage" . DS . "tmp" . DS;
		File::mkdir($path);

		return $path;
	}

	protected static function tmpRoot()
	{
		return URL::root(false) . "storage/tmp/";
	}

	protected static function tmpKey()
	{
		return time() . substr("00000" . rand(1, 999999), -6);
	}

	protected static function unique($path)
	{
		if (File::exists($path))
		{
			$ext = File::extension($path);
			$file = File::removeExtension(File::fileName($path));
			$path = File::dirName($path);

			if (preg_match ('/(.*)-(\d+)$/', $file, $matches))
			{
				$num = (int)$matches[2] + 1;
				$file = $matches[1];
			}
			else
			{
				$num = 1;
			}

			$file = $file . "-" . $num . "." . $ext;

			return static::unique($path . $file);
		}

		return $path;
	}

	public function __construct($name, $label, $path)
	{
		parent::__construct($name, $label);
		$this->type = "upload";

		$id = uniqueID();
		$this->sessionKey = "manager_" . $this->type . $id;
		$this->updateURL = "fields/" . $this->type . $id;
		$this->limit = 1;
		$this->path = $path;
		$this->defaultValue = array();
		$this->accepts = array();
		$this->acceptsMask = null;

		$that = $this;

		Router::register("POST", "manager/api/" . $this->updateURL . "/(:segment)/(:num)/(:segment)", function ($action, $id, $flag) use ($that) {
			if (($token = User::validateToken()) !== true)
			{
				return $token;
			}

			$flag = Str::upper($flag);
			$that->module->flag = $flag;

			switch ($action) {
				default:
				case "update":
					return Response::json($that->upload($flag, $id));

					break;
				case "sort":
					return Response::json($that->sort($id, Request::post("from", -1), Request::post("to", -1)));

					break;
				case "delete":
					return Response::json($that->delete($id, (int)Request::post("index", -1)));

					break;
			}

			return Response::code(500);
		});
	}

	public function accepts($mimetypes)
	{
		if (is_array($mimetypes))
		{
			$accepts = $mimetypes;
		}
		else
		{
			$accepts = explode(",", $mimetypes);
		}

		if (!is_null($this->acceptsMask))
		{
			$accepts = array_intersect($accepts, $this->acceptsMask);
		}

		$this->accepts = array_unique($accepts);
	}

	public function config()
	{
		$arr = parent::config();

		return array_merge(array(
			"limit" => $this->limit,
			"update_url" => "api/" . $this->updateURL,
			"accepts" => implode(",", $this->accepts)
		), $arr);
	}

	public function init()
	{
		if ($this->module->flag == "C" && !$this->module->orm)
		{
			$this->updateFilesList(0, array());
		}
	}

	public function value()
	{
		$value = @json_decode($this->module->orm->field($this->name), true);

		if (!is_array($value))
		{
			$value = array();
		}

		$id = $this->module->orm->field("id");
		$this->updateFilesList($id, $value);

		return $this->items($id);
	}

	protected function items($id)
	{
		$files = $this->filesList($id);
		$items = array();

		foreach ($files as $file)
		{
			if (array_key_exists("_tmpName", $file))
			{
				$items[] = array(
					"path" => static::tmpRoot() . $file["_tmpName"],
					"name" => $file["_name"]
				);
			}
			else
			{
				$items[] = array(
					"path" => static::storageRoot() . $file["path"],
					"name" => File::fileName($file["path"])
				);
			}
		}

		return $items;
	}

	public function save($value)
	{
		$flag = $this->module->flag;
		$path = File::formatDir($this->path);
		$destPath = static::storagePath() . File::formatDir($this->path);
		
		File::mkdir($destPath);

		if (!is_writable($destPath))
		{
			Response::code(500);
			return Response::json(array(
				"error" => true,
				"error_description" => "Directory '" . $destPath . "' is not writtable."
			));
		}

		$id = (int)$this->module->orm->field("id");
		$files = $this->filesList($id);

		if ($flag == "U" || $flag == "D")
		{
			$oldFiles = $this->module->orm->field($this->name);

			if (!is_array(@json_decode($oldFiles, true)))
			{
				$oldFiles = json_encode(array());
			}

			$oldFiles = json_decode($oldFiles, true);

			if ($flag == "U")
			{
				//Delete files that are on our table but not on our session list
				foreach ($oldFiles as $oldFile)
				{
					$found = false;

					foreach ($files as $file)
					{
						if (!array_key_exists("_tmpName", $file) && ($oldFile["path"] == $file["path"]))
						{
							$found = true;
							break;
						}
					}

					if (!$found)
					{
						File::delete(static::storagePath() . $oldFile["path"]);
					}
				}
			}
			else if ($flag == "D")
			{
				//Delete all files
				foreach ($oldFiles as $oldFile)
				{
					File::delete(static::storagePath() . $oldFile["path"]);
				}

				if (count(File::lsdir($destPath)) == 0)
				{
					File::rmdir($destPath);
				}
			}
		}

		if ($flag == "C" || $flag == "U")
		{
			//Copy tmp files to it's target place and save
			foreach ($files as $k => $file)
			{
				if (array_key_exists("_tmpName", $file))
				{
					$unique = static::unique($destPath . $file["_name"]);
					File::move(static::tmpPath() . $file["_tmpName"], $unique);

					$files[$k] = array(
						"path" => $path . File::fileName($unique)
					);
				}
			}

			$this->module->orm->setField($this->name, json_encode($files));
		}
	}

	public function upload($flag, $id)
	{
		if (isset($_FILES["attachment"]))
		{
			$info = $_FILES["attachment"];
			$num = count($info["name"]);

			$files = $this->filesList($id);

			$remaining = $this->limit - count($files);
			$num = min($num, $remaining);

			if ($remaining <= 0)
			{
				return array(
					"error" => true,
					"error_description" => "File limit reached."
				);
			}

			$ok = 0;
			$acceptError = 0;
			$permissionError = 0;

			for ($i = 0; $i < $num; $i++)
			{
				$ext = File::extension($info["name"][$i]);

				if (count($this->accepts) > 0)
				{
					$mime = File::mime($ext);

					if (array_search($mime, $this->accepts) === false)
					{
						// echo $mime . "\n";
						// print_r($this->accepts);

						$acceptError++;
						continue;
					}
				}

				$fileName = File::removeExtension($info["name"][$i]);
				$destFile = $fileName . static::tmpKey() . "." . $ext;

				if (@move_uploaded_file($info["tmp_name"][$i], static::tmpPath() . $destFile))
				{
					$files[] = array(
						"_name" => $fileName . "." . $ext,
						"_tmpName" => $destFile
					);

					$ok++;
				}
				else
				{
					$permissionError++;
				}
			}

			$this->updateFilesList($id, $files);

			if ($permissionError > 0 && $ok == 0)
			{
				return array(
					"error" => true,
					"error_description" => "File permission error. Contact developer."
				);
			}
			else if ($acceptError > 0 && $ok == 0)
			{
				return array(
					"error" => true,
					"error_description" => "File type not allowed."
				);
			}

			return array(
				"error" => false,
				"items" => $this->items($id)
			);
		}

		return array(
			"error" => true,
			"error_description" => "This file is bigger than the server limit of " . ini_get('upload_max_filesize') . "."
		);
	}

	public function delete($id, $index)
	{
		if ($index >= 0)
		{
			$files = $this->filesList($id);

			if ($index < count($files))
			{
				$file = $files[$index];

				if (array_key_exists("_tmpName", $file))
				{
					File::delete(static::tmpPath() . $file["_tmpName"]);
				}

				array_splice($files, $index, 1);

				$this->updateFilesList($id, $files);

				return array(
					"error" => false,
					"items" => $this->items($id)
				);
			}
			else
			{
				return array(
					"error" => true,
					"error_description" => "Index inválido"
				);
			}
		}
		return array(
			"error" => true,
			"error_description" => "Index inválido"
		);
	}

	public function sort($id, $from, $to)
	{
		if ($from == -1 || $to == -1)
		{
			return array(
				"error" => true,
				"error_description" => "Index inválido"
			);
		}

		$files = $this->filesList($id);

		$tmp = $files[$from];
		$files[$from] = $files[$to];
		$files[$to] = $tmp;

		$this->updateFilesList($id, $files);

		return array(
			"error" => false,
			"items" => $this->items($id)
		);
	}

	protected function filesList($id)
	{
		$v = Session::get($this->sessionKey . "_" . $id);
		if ($v)
		{
			$v = json_decode($v, true);
		}
		else
		{
			$v = array();
			$this->updateFilesList($id, $v);
		}

		return $v;
	}

	protected function updateFilesList($id, $files)
	{
		Session::set($this->sessionKey . "_" . $id, json_encode($files));
	}


}
?>