<?php
    abstract class DomainRoute
    {
        public const API     = 0x0001;
        public const DESKTOP = 0x0002;
        public const MOBILE  = 0x0004;

        private $domain_;

        protected function __construct($domain)
        {
            $this->domain_ = $domain;
        }

        public function get_domain()
        {
            return $this->domain_;
        }

        abstract public function manage_exception($exception);

        abstract public function resource_not_found($query);
    }
?>