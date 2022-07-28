--TEST--
Test that backed enum classes are correctly copied
--SKIPIF--
<?php if(PHP_VERSION_ID < 80100) die("skip this test is for 8.1+"); ?>
--FILE--
<?php

enum TestEnum : string{
	case A = "yes";
	case B = "yes2";
}

function test() : void{
	foreach(TestEnum::cases() as $case){
		var_dump($case->value);
	}
}

$t = new class extends \Thread{
	public function run(){
		test();
	}
};
$t->start() && $t->join();

test();

?>
--EXPECT--
string(3) "yes"
string(4) "yes2"
string(3) "yes"
string(4) "yes2"
