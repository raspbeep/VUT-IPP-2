# VUT-IPP-2
11.6/13b

### Hodnotenie
Hodnocené části (nehodnocené části jsou vynechány):
1) Automatické testy interpret.py - základní.
2) Automatické testy interpret.py - registrovaná rozšíření (uvedená ve vašem souboru rozsireni). Zde zatím není aplikováno omezení na 5 bonusových bodů celkem.
2b) Manuální hodnocení rozšíření NVI (případný komentář je uveden u komentářů k dokumentaci).
3) Automatické testy test.php (základní a registrovaná rozšíření) včetně ručního zhodnocení kvalit vytvořených HTML reportů.
4) Případné malusy a bonusy (pozdní odevzdání, příspěvky na fóru, ...). Zde též není aplikováno omezení na 5 bonusových bodů celkem.
5) Hodnocení dokumentace readme2(.pdf/.md) a štábní kultury zdrojových kódů (především komentářů). Za bodovým hodnocením dokumentace je v závorkách 30% korelace vzhledem k hodnocení z části 1)+3).

Ad 3) Následuje seznam zkratek, které se mohou vyskytnout v komentářích k hodnocení test.php:
 OK = výstupy byly v pořádku
 EMPTY = prázdný (výstupy pravděpodobně nesměřujete na stdout dle zadání) nebo téměř prázdný HTML soubor
 NO = žádné smysluplné výsledky
 NOINT = nefunguje testování interpretu
 NODIFF = špatné porovnání výstupů
 NOPRE = nefunguje dogenerování souborů s implicitním obsahem
 NOSTOP = nezastavení po chybě v parse.php a následný pokus interpretace nevalidního XML
 NOUTF = chybí podpora Unicode znaků (např. v názvech adresářů a souborů), nebo nefunguje vyhodnocování testů s Unicode znaky v názvech testů/adresářů
 NODETAILS = schází informace o (jednotlivých) testech či jejich počtech
 PARSEONLY = funguje jen vesměs --parse-only (výsledky pro ostatní testy jsou většinou špatně)
 WARNINGS = varování PHP/Python vložené přímo do výstupního HTML (často nepřehledně)
 WRONG = neodpovídající výsledky testů (např. validní test je označen jako nevalidní)
 WRONG31 - většina testů není správně označena jako validní kvůli chybě 31 z interpretu (možná špatné použití argumentů --input a --source, možná použita špatná verze interpretu PHP 8.1/Python 3.8)
 INTONLY = uspívaly pouze testy s parametrem --int-only (může se jednat o chybu WRONG31, ale v HTML5 reportu nebylo dost informací)
 ERRORS = jiné chyby zpracování (např. nenalezení/nezobrazení všech testů nebo nalezení i těch, které se nalézt neměly)
 JEXAMXML = chyba při použití parametru --jexamxml
 UX = report je nepřehledný
 STAT = nesprávně vypočtená nebo chybějící statistika úspěnosti provedených testů

Ad 5) Následuje seznam zkratek, které se mohou vyskytnout v komentářích k hodnocení dokumentace a štábní kultury skriptů:
Vysvětlivky zkratek v dokumentaci:
 CH = pravopisné chyby, překlepy
 FORMAT = špatný formát vzhledu dokumentu (nedodrženy požadavky)
 SHORT = nesplňuje minimální požadavky na délku či obsah
 STRUCT = nevhodně strukturováno (např. bez nadpisů)
 MISSING = dokumentace nebyla odevzdána (nebo chybí její významná část: ohledně interpret.py cca 50 %, test.php cca 25 % a přehlednost a komentování kódu cca 25 %)
 COPY = text obsahuje úryvky ze zadání nebo cizí necitované materiály
 STYLE = stylizace vět, nečitelnost, nesrozumitelnost
 COMMENT = chybějící nebo nedostatečné komentáře ve zdrojovém textu
 FILO = nedostatečná filosofie návrhu (abstraktní popis struktury programu, co následuje za čím)
 JAK = technicky nedostatečný popis řešení
 SRCFORMAT = opravdu velmi špatná štábní kultura zdrojového kódu
 SPACETAB (jen pro informaci) = kombinování mezer a tabelátorů k odsazování zdrojového textu
 DECOMPOSE	= skript není vůbec/dostatečně dekomponován na funkce (příp. třídy a metody), nešikovné opakování regulárních výrazů
 AUTHOR (jen pro informaci) = ve skriptu chybí jméno (login) autora
 OOP, NV, EX = smysluplné a dokumentované využití objektového paradigmatu, návrhových vzorů, nebo výjimek
 LANG = míchání jazyků (většinou anglické termíny v českém textu)
 HOV = hovorové nebo nevhodné slangové výrazy
 FORM = nepěkná úprava, nekonzistentní velikost a typ písma apod.
 TERM = problematická terminologie (neobvyklá, nepřesná či přímo špatná)
 IR = nedostatečně popsaná vnitřní reprezentace (např. pro paměť, sekvenci instrukcí apod.)
 PRED (jen pro informaci) = pozor na osamocené neslabičné předložky na konci řádků
 BLOK (jen pro informaci) = chybí zarovnaní do bloku místo méně pěkného zarovnání na prapor (doleva)
 KAPTXT (jen pro informaci) = mezi nadpisem a jeho podnadpisem by měl být vždy nějaký text
 MEZ (jen pro informaci) = za otevírající nebo před zavírající závorku mezera nepatří, případně další prohřešky při sazbě mezer
 ICH (jen pro informaci) = ich-forma (psaní v první osobě jednotného čísla) není většinou vhodná pro programovou dokumentaci
 SAZBA (jen pro informaci) = alespoň identifikátory proměnných a funkcí se patří sázet písmem s jednotnou šířkou písmen (např. font Courier)
 WHY = u rozšíření NVP/NVI nebylo v dokumentaci řádné použití návrhového vzoru zdůvodněno
 OK = k dokumentaci byly nanejvýše nepodstatné připomínky


Termíny osobních reklamací budou vypsány ve WIS (viz termín 'Reklamace hodnocení 2. úlohy', primárně do čtvrtka 5. 5. 2022). Je možné vést reklamaci i přes e-mail, což budu vyřizovat dle časových možností (krivka@fit.vut.cz a !!v odpovědi zachovejte i text tohoto e-mailu!!).


Vaše hodnocení části 1): 7,70 bodů
Vaše hodnocení části 2): 0,00 bodů
Vaše hodnocení části 3): 2,15 bodů
 Komentář hodnocení části 3): WRONG, EMPTY
 Body za rozšíření k části 3): 0,00 bodů
Vaše hodnocení části 5): 1,70 bodů (po 30% korelaci 1,70 bodů)
 Komentář hodnocení části 5) (srážky uváděny v minibodech, 1 bod = 100 minibodů): IR (rámce, seznam instrukcí, použité třídy) -30, BLOK, MEZ

Pokud jste obdrželi výsledek částí 1) mimo hodnotící interval, tak
bude oříznut, tak že získáte za implementaci alespoň 0 a ne více jak maximum bodů za daný skript.

Dekomprimace archivu proběhla úspěšně.

Procentuální hodnocení jednotlivých kategorií skriptu interpret.py: 
Lexikální analýza (detekce chyb): 100%
Syntaktická analýza (detekce chyb): 73%
Sémantická analýza (detekce chyb): 85%
Běhové chyby (detekce): 94%
Interpretace instrukcí: 95%
Interpretace netriviálních programů: 100%
Volby příkazové řádky: 100%
Rozšíření FLOAT 0%
Rozšíření STACK 0%
Rozšíření STATI 0%
Celkem bez rozšíření: 94%
