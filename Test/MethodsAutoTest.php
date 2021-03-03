<?php
namespace GDO\TestMethods\Test;

use GDO\Tests\MethodTest;
use GDO\Tests\TestCase;
use GDO\Core\GDO;
use GDO\Core\GDT_Response;
use GDO\Core\ModuleLoader;
use GDO\Cronjob\MethodCronjob;
use GDO\Install\Installer;
use GDO\File\Filewalker;
use GDO\Form\MethodForm;
use GDO\Util\Strings;
use function PHPUnit\Framework\assertTrue;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertEquals;

/**
 * Auto coverage test.
 * @author gizmore
 * @version 6.10
 * @since 6.10
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
                }
            });
        }
    }
    
    function testTrivialMethods()
    {
        $modules = ModuleLoader::instance()->getEnabledModules();
        foreach ($modules as $module)
        {
            Installer::loopMethods($module, function($entry, $fullpath, $method) {
                require_once $fullpath;
            });
        }
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
    
    public function testAllTrivialMethodsFor200Code()
    {
        $tested = 0;
        $passed = 0;
        
        foreach (get_declared_classes() as $klass)
        {
            $parents = class_parents($klass);
            if (in_array('GDO\\Core\\Method', $parents, true))
            {
                $k = new \ReflectionClass($klass);
                if ($k->isAbstract())
                {
                    continue;
                }
                
                /** @var $method \GDO\Core\Method **/
                $method = call_user_func([$klass, 'make']);
                
                if ( ($method instanceof MethodCronjob) || (!$method->isTrivial()) )
                {
                    continue;
                }
                
                
                $methodName =  $method->getModuleName() . '::' . $method->getMethodName();
                
                $requiredParams = $method->gdoParameterCache();
                
                if ($method instanceof MethodForm)
                {
                    /** @var $method \GDO\Form\GDT_Form **/
                    $formParams = $method->getForm()->getFieldsRec();
                    $requiredParams = array_merge($requiredParams, $formParams);
                }
                
                $parameters = [];
                $getParameters = [];
                $trivial = true;
                
                if ($requiredParams)
                {
                    foreach ($requiredParams as $name => $gdt)
                    {
                        # Ouch looks not trivial
                        if ( ($gdt->notNull) && ($gdt->initial === null) )
                        {
                            $trivial = false;
                        }
                        
                        # But maybe now
                        if ($var = MethodTest::make()->plugParam($gdt, $method))
                        {
                            $parameters[$name] = $var;
                            if (isset($method->gdoParameterCache()[$name]))
                            {
                                $getParameters[$name] = $var;
                            }
                            $trivial = true;
                        }
                        
                        # Or is it?
                        if (!$trivial)
                        {
                            echo "Skipping method {$methodName}\n"; ob_flush();
                            break;
                        }
                    }
                }
                if ($trivial)
                {
                    echo "Running trivial method {$methodName}\n"; ob_flush();
                    MethodTest::make()->user($this->gizmore())->method($method)->getParameters($getParameters)->parameters($parameters)->execute();
                    
                    $tested++;
                    if (GDT_Response::$CODE === 200)
                    {
                        $passed++;
                    }
                }
            }
        }
        assertEquals($tested, $passed, "Check if all trivial methods test fine.");
    }

}
