<?php

class view {

    private $templateFileName = "";
    private $parameters = array();
    private $parsed = false;

  /** \brief -
   */
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

  /** \brief -
   */
    function __destruct() {
        if (!$this->parsed)
            $this->parse();
    }

  /** \brief -
   */
    public function set($var, $value) {
        $this->parameters[$var] = $value;
    }

  /** \brief -
   */
    public function parse() {
        foreach ($this->parameters as $parameter_variable_name => $parameter_value)
            $$parameter_variable_name = $parameter_value;
        unset($parameter_variable_name, $parameter_value);  // Do not expose these variables to the template
        require($this->templateFileName);
        $this->parsed = true;
    }

}

?>
