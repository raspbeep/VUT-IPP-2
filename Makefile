all: clean

pack:
	zip xkrato61.zip interpret.py parse.php test.php readme2.md

clean:
	rm -f xkrato61.zip
	rm -rf xkrato61

test:
	rm -rf test
	cp -r test_template test