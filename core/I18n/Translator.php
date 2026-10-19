<?php
declare(strict_types=1);
namespace Brightery\Core\I18n;
final class Translator {
    private array $loaded=[];
    public function __construct(private string $locale='en') {}
    public function locale(): string {return $this->locale;}
    public function setLocale(string $locale): void {if(preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/',$locale))$this->locale=$locale;}
    public function get(string $key,array $replace=[]): string {
        $lang=$this->load($this->locale);
        $string=$lang[$key]??$this->load('en')[$key]??$key;
        foreach ($replace as $name=>$value)$string=str_replace(':'.$name,(string)$value,$string);
        return $string;
    }
    public function load(string $locale): array {
        if(!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/',$locale))return [];
        if(isset($this->loaded[$locale]))return $this->loaded[$locale];
        $file=base_path('lang/'.$locale.'.json');return $this->loaded[$locale]=is_file($file)?(json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR)?:[]):[];
    }
    public function save(string $locale,array $strings): void {
        if(!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/',$locale))throw new \InvalidArgumentException('Invalid locale');
        foreach ($strings as $key=>$value)if(!is_string($key)||!is_string($value)||strlen($key)>200||strlen($value)>10000)throw new \InvalidArgumentException('Invalid translation entry');
        ksort($strings);$path=base_path('lang/'.$locale.'.json');$temp=tempnam(dirname($path),'lang');
        file_put_contents($temp,json_encode($strings,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));rename($temp,$path);
        unset($this->loaded[$locale]);
    }
}
