<?php
/**
 * Test that Bootstrap 5 spinners replace the old Throbber.js library
 */

class Test_SpinnerReplacement
{
    private string $base_dir;
    /** @var array<int,string> */
    private array $failures = [];

    public function __construct()
    {
        $this->base_dir = dirname(__FILE__) . '/..';
    }

    /**
     * Run all tests
     */
    public function run(): int
    {
        echo "Running Spinner Replacement Tests...\n";
        $this->test_throbber_library_removed();
        $this->test_throbber_images_removed();
        $this->test_minify_inputs_no_throbber();
        $this->test_header_no_throbber_tag();
        $this->test_about_page_credits_updated();
        $this->test_about_page_dependency_facts();
        $this->test_wrapper_function_uses_spinner();

        if ( empty($this->failures) )
        {
            echo "OK: all spinner replacement assertions passed (PHP " . PHP_VERSION . ")\n";
            return 0;
        }
        else
        {
            echo "Tests failed:\n";
            foreach ( $this->failures as $failure )
            {
                echo "  FAIL: $failure\n";
            }
            return 1;
        }
    }

    /**
     * Test that old Throbber library file is removed
     */
    private function test_throbber_library_removed(): void
    {
        $throbber_path = $this->base_dir . '/public/js/310-throbber.js';
        if ( file_exists($throbber_path) )
        {
            $this->failures[] = 'Throbber.js library should be removed';
        }
        else
        {
            echo "  OK: Throbber library removed\n";
        }
    }

    /**
     * Test that throbber images are removed
     */
    private function test_throbber_images_removed(): void
    {
        $gif_16 = $this->base_dir . '/public/images/throbber_16px.gif';
        $gif_32 = $this->base_dir . '/public/images/throbber_32px.gif';

        if ( file_exists($gif_16) )
        {
            $this->failures[] = 'throbber_16px.gif should be removed';
        }
        else
        {
            echo "  OK: throbber_16px.gif removed\n";
        }

        if ( file_exists($gif_32) )
        {
            $this->failures[] = 'throbber_32px.gif should be removed';
        }
        else
        {
            echo "  OK: throbber_32px.gif removed\n";
        }
    }

    /**
     * Test that minify bundle input no longer registers throbber
     */
    private function test_minify_inputs_no_throbber(): void
    {
        $inputs_file = $this->base_dir . '/bin/minify-bundle-files.php';
        $content = file_get_contents($inputs_file);
        if ( $content === false )
        {
            $this->failures[] = 'minify-bundle-files.php could not be read';
            return;
        }

        if ( strpos($content, '310-throbber.js') !== false )
        {
            $this->failures[] = 'minify-bundle-files.php should not register 310-throbber.js';
        }
        else
        {
            echo "  OK: minify-bundle-files.php cleaned\n";
        }
    }

    /**
     * Test that header includes no throbber script tag
     */
    private function test_header_no_throbber_tag(): void
    {
        $header_file = $this->base_dir . '/application/views/header-js.phtml';
        $content = file_get_contents($header_file);
        if ( $content === false )
        {
            $this->failures[] = 'header-js.phtml could not be read';
            return;
        }

        if ( strpos($content, '310-throbber.js') !== false )
        {
            $this->failures[] = 'header-js.phtml should not load 310-throbber.js';
        }
        else
        {
            echo "  OK: header-js.phtml cleaned\n";
        }
    }

    /**
     * Test that about page credits no longer mention throbber
     */
    private function test_about_page_credits_updated(): void
    {
        $about_file = $this->base_dir . '/application/views/index/about.phtml';
        $content = file_get_contents($about_file);
        if ( $content === false )
        {
            $this->failures[] = 'about.phtml could not be read';
            return;
        }

        if ( stripos($content, 'throbber') !== false )
        {
            $this->failures[] = 'about.phtml should not reference Throbber in credits';
        }
        else
        {
            echo "  OK: about.phtml credits updated\n";
        }
    }

    private function test_about_page_dependency_facts(): void
    {
        $content = file_get_contents($this->base_dir . '/application/views/index/about.phtml');
        $componentCredits = '';
        if ($content !== false
            && preg_match('/<p>\s*Additional components include(?<credits>.*?)<\/p>/s', $content, $matches) === 1
        ) {
            $componentCredits = $matches['credits'];
        }
        $facts = str_contains($componentCredits, 'href="https://datatables.net/"')
            && str_contains($componentCredits, '>DataTables</a>')
            && str_contains($componentCredits, 'dependency-free')
            && str_contains($componentCredits, 'href="https://icons.getbootstrap.com/"')
            && str_contains($componentCredits, '>Bootstrap Icons</a>');
        if (!$facts) {
            $this->failures[] = 'about.phtml should credit dependency-free DataTables and Bootstrap Icons';
        } else {
            echo "  OK: about.phtml dependency facts retained\n";
        }
    }

    /**
     * Test that wrapper function uses spinner classes
     */
    private function test_wrapper_function_uses_spinner(): void
    {
        $js_file = $this->base_dir . '/public/js/990-vimbadmin.js';
        $content = file_get_contents($js_file);
        if ( $content === false )
        {
            $this->failures[] = '990-vimbadmin.js could not be read';
            return;
        }

        // Assert against the tt_throbber wrapper body, not the whole file: a
        // stray 'spinner-border' anywhere else must not satisfy this test.
        if ( !preg_match('/function\s+tt_throbber\s*\([^)]*\)\s*\{(.*?)\n\}/s', $content, $m) )
        {
            $this->failures[] = '990-vimbadmin.js: tt_throbber wrapper not found';
            return;
        }

        $wrapper = $m[1];

        // Match the construction call itself, not any mention of the class:
        // 'spinner-border-sm' in the size ladder must not satisfy this.
        if ( !preg_match('/\.addClass\(\s*[\'"]spinner-border[\'"]\s*\)/', $wrapper) )
        {
            $this->failures[] = 'tt_throbber should addClass(\'spinner-border\') on the element';
        }
        else
        {
            echo "  OK: tt_throbber adds the spinner-border class\n";
        }

        if ( !preg_match('/role[\'"]?\s*,\s*[\'"]status[\'"]/', $wrapper) )
        {
            $this->failures[] = 'tt_throbber should set role="status" for assistive technology';
        }
        else
        {
            echo "  OK: tt_throbber sets role=status\n";
        }

        // The AJAX error handler must tear down only the spinners this wrapper
        // owns. 'spinner-border' is a generic Bootstrap utility class, so a
        // document-wide removal would delete unrelated loading indicators
        // belonging to other in-flight requests or page features.
        if ( !preg_match('/\.addClass\(\s*[\'"]vb-throbber[\'"]\s*\)/', $wrapper) )
        {
            $this->failures[] = 'tt_throbber should tag its spinners with the vb-throbber class';
        }
        else
        {
            echo "  OK: tt_throbber tags its spinners with vb-throbber\n";
        }

        if ( preg_match('/\$\(\s*[\'"]\.spinner-border[\'"]\s*\)/', $content) )
        {
            $this->failures[] = 'error handler should not select .spinner-border document-wide; scope teardown to .vb-throbber';
        }
        else
        {
            echo "  OK: no document-wide .spinner-border teardown selector\n";
        }

        // Catches 'new Throbber(', 'new window.Throbber(' and bare Throbber(...
        // calls alike; the library is gone, so any reference is a defect.
        if ( preg_match('/\bThrobber\s*\(/', $wrapper) || preg_match('/\bThrobber\b/', $wrapper) )
        {
            $this->failures[] = 'tt_throbber should not reference the removed Throbber library';
        }
        else
        {
            echo "  OK: tt_throbber does not reference Throbber\n";
        }
    }
}

$test = new Test_SpinnerReplacement();
exit( $test->run() );
