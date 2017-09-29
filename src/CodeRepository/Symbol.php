<?php

namespace LanguageServer\CodeRepository;

interface Symbol {
    function getName(): string;
    function getFQN(): string;
}
