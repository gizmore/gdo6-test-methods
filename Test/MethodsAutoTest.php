<?php
namespace GDO\TestMethods\Test;

use GDO\Tests\MethodTest;
use GDO\Tests\TestCase;
use GDO\Core\GDO;
use GDO\Core\GDT_Response;
use GDO\Core\ModuleLoader;
use GDO\Install\Installer;
use GDO\File\Filewalker;
use GDO\Form\MethodForm;
use GDO\Util\Strings;
use function PHPUnit\Framework\assertTrue;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use GDO\ThemeSwitcher\GDO_Theme;
use GDO\ThemeSwitcher\Method\Set;
use function PHPUnit\Framework\assertLessThanOrEqual;
use GDO\Language\Trans;

/**
 * Auto coverage test.
 * Includes all GDT and tries some basic make and nullable and conversion.
 * Includes all GDO and tests basic blank data.
 * Includes all Method and tries trivial ones automatically. Trivial methods have parameters that can be plugged automatically.
 * @author gizmore
 * @version 6.10.1
 * @since 6.10.0
 */
final class MethodsAutoTest extends TestCase
{
    function testGDOandGDTsyntax()
    {
        $modules = ModuleLoader::instance()->getEnabledModules();
        foreach ($modules as $module)
        {
            Filewalker::traverse($module->filePath(), null, function($entry, $fullpath) {
                if ( (Strings::startsWith($entry, 'GDT')) ||
                     (Strings::startsWith($entry, 'GDO')))
                {
                    require_once $fullpath;
                    assertTrue(true, 'STUB assert. We check for crash only.');
                }
            });
        }
    }
    
    private $numMethods = 0;
    function testTrivialMethods()
    {
        $modules = ModuleLoader::instance()->getEnabledModules();
        foreach ($modules as $module)
        {
            Installer::loopMethods($module, function($entry, $fullpath, $method) {
                $this->numMethods++;
                require_once $fullpath;
            });
        }
        assertGreaterThanOrEqual(1, $this->numMethods, 'Check if we included at least one more method for auto coverage.');
    }

    public function testEveryGDTConstructors()
    {
        $count = 0;
        echo "Testing null handling on all GDT\n"; ob_flush();
        foreach (get_declared_classes() as $klass)
        {
            $parents = class_parents($klass);
            if (in_array('GDO\\Core\\GDT', $parents, true))
            {
                /** @var $gdt \GDO\Core\GDT **/
                
                $k = new \ReflectionClass($klass);
                if ($k->isAbstract())
                {
                    continue;
                }
                
                echo "Testing $klass\n";
                
                $gdt = call_user_func([$klass, 'make']);
                $gdt->value(null);
                $value = $gdt->getValue();
                $gdt->value($value);
                $count++;
                assertTrue(!!$gdt, "Check if GDT can be created."); # fake assert
            }
        }
        echo "$count GDT tested\n";
    }
    
    public function testAllGDOConstructors()
    {
        $count = 0;
        echo "Testing blank() handling on all GDO\n"; ob_flush();
        foreach (get_declared_classes() as $klass)
        {
            $k = new \ReflectionClass($klass);
            if ($k->isAbstract())
            {
                continue;
            }
            
            $parents = class_parents($klass);
            if (in_array('GDO\\Core\\GDO', $parents, true))
            {
//              echo "Checking GDO $klass\n"; ob_flush();
                $table = GDO::tableFor($klass);
                if ($table)
                {
                    $count++;
                    # Test GDO creation.
//                  echo "Testing GDO $klass\n"; flush();
                    $gdo = call_user_func([$klass, 'blank']);
                    assertInstanceOf(GDO::class, $gdo, 'Test if '.$klass.' is a GDO.');
                }
            }
        }
        echo "{$count} GDO tested\n"; ob_flush();
    }
    
    public function testAllTrivialMethodsForOKCode()
    {
        if (!module_enabled('ThemeSwitcher'))
        {
            echo "Testing all trivial methods with current theme.\n";
            ob_flush();
            $this->doAllMethods();
        }
        else
        {
            assertTrue(true);
        }
    }
    
    public function testAllThemesForAllTrivialMethods()
    {
        if (module_enabled('ThemeSwitcher'))
        {
            foreach (GDO_Theme::table()->all() as $theme)
            {
                $this->testThemeForAllMethods($theme);
            }
        }
        else
        {
            assertTrue(true);
            echo "Theme switcher is disabled. done.\n";
            ob_flush();
        }
    }
    
    private function testThemeForAllMethods(GDO_Theme $theme)
    {
        echo "Testing all trivial methods with {$theme->displayName()}\n";
        ob_flush();
        
        # Switch theme
        MethodTest::make()->method(Set::make())->
            getParameters(['theme' => $theme->getID()])->execute();
        
        # Do methods
        $this->doAllMethods();
    }
    
    private function doAllMethods()
    {
        $n = 1;
        $tested = 0;
        $passed = 0;
        $skippedAuto = 0;
        $skippedManual = 0;
        
        foreach (get_declared_classes() as $klass)
        {
            $parents = class_parents($klass);
            if (in_array('GDO\\Core\\Method', $parents, true))
            {
                # Skip abstract
                $k = new \ReflectionClass($klass);
                if ($k->isAbstract())
                {
                    continue;
                }
                
                # Check others
                /** @var $method \GDO\Core\Method **/
                $method = call_user_func([$klass, 'make']);
                $methodName =  $method->getModuleName() . '::' . $method->getMethodName();
                echo "?.) Checking method {$methodName} to be trivial...\n"; ob_flush();
                
                # Skip special marked
                if (!$method->isTrivial())
                {
                    echo "{$methodName} is skipped because it is explicitly marked as not trivial.\n"; ob_flush();
                    $skippedManual++;
                    continue;
                }
                
                
                $fields = $method->gdoParameterCache();
                
                $parameters = [];
                $getParameters = [];
                $trivial = true;
                
                if ($fields)
                {
                    foreach ($fields as $name => $gdt)
                    {
                        # Ouch looks not trivial
                        if ( ($gdt->notNull) && ($gdt->toValue($gdt->initial) === null) )
                        {
                            $trivial = false;
                        }
                        
                        # But maybe now
                        if (!$trivial)
                        {
                            if ($var = MethodTest::make()->plugParam($gdt, $method))
                            {
                                $getParameters[$name] = $_REQUEST[$name] = $_GET[$name] = $var;
                                $trivial = true;
                            }
                            else
                            {
                                break;
                            }
                        }
                    }
                }
                
                if (!$trivial)
                {
                    echo "Skipping {$methodName} because it has weird get parameters.\n"; ob_flush();
                    $skippedAuto++;
                    continue;
                }
                
                # Now check form
                /** @var $method MethodForm **/
                if ($method instanceof MethodForm)
                {
                    $method->init();
                    $form = $method->getForm();
                    $fields = $form->getFieldsRec();
                    
                    foreach ($fields as $name => $gdt)
                    {
                        # needs to be plugged
                        if ( ($gdt->notNull) && ($gdt->toValue($gdt->initial) === null) )
                        {
                            $trivial = false;
                        }
                                
                        # try to plug and be trivial again
                        if (!$trivial)
                        {
                            if ($var = MethodTest::make()->plugParam($gdt, $method))
                            {
                                $frm = $form->name;
                                $_REQUEST[$frm][$name] = $_POST[$frm][$name] = $var;
                                $parameters[$name] = $var;
                                $trivial = true;
                            }
                        }
                        
                        # Or is it?
                        if (!$trivial)
                        {
                            echo "Skipping {$methodName} because it has weird form parameters.\n"; ob_flush();
                            $skippedAuto++;
                            break;
                        }
                    } # foreach form fields
                } # is MethodForm
                
                # Execute trivial method
                if ($trivial)
                {
                    $n++;
                    echo "$n.) Running trivial method {$methodName}\n"; ob_flush();
                    MethodTest::make()->user($this->gizmore())->method($method)->getParameters($getParameters)->parameters($parameters)->execute();
                    
                    $tested++;
                    if (GDT_Response::$CODE === 200)
                    {
                        $passed++;
                    }
                    assertEquals($tested, $passed, "$n.) $methodName should be trivially returning status code 200.");
                } # trivial call
            } # is Method
        } # foreach classes
        echo "Tested $tested trivial methods who all have passed.\n$skippedAuto have been skipped because they were unpluggable.\n$skippedManual have been manually skipped via config.\n";
        ob_flush();
    } # test func

    public function testLanguageFilesForCompletion()
    {
        if (Trans::$MISS)
        {
            echo "The following lang keys are missing:\n\n";
            foreach (Trans::$MISSING as $key)
            {
                echo " - $key\n";
            }
//             ob_flush();
        }
        
        assertEquals(0, Trans::$MISS, 'Assert that no internationalization was missing.');
    }
    
}
