<?php

class view {

    private $templateFileName = "";
    private $parameters = array();
    private $parsed = false;

    function __construct($template = "") {
        if (empty($template))
            $template = basename($_SERVER['SCRIPT_FILENAME'], 'php') . 'thtml';
        if (!file_exists($template))
            die('Unknown template/html file');
        else {
            $template = getcwd() . "/" . $template;
        }
        $this->templateFileName = $template;
    }

    function __destruct() {
        if (!$this->parsed)
            $this->parse();
    }

    public function set($var, $value) {
        $this->parameters[$var] = $value;
    }

    public function parse() {
        foreach ($this->parameters as $parameter_variable_name => $parameter_value)
            $$parameter_variable_name = $parameter_value;
        unset($parameter_variable_name, $parameter_value);  // Do not expose these variables to the template
        require($this->templateFileName);
        $this->parsed = true;
    }

}

?>