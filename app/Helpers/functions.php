<?php

function generateRandomInteger($length = 10)
{
    return (int) substr(str_shuffle("123456789"), 0, $length);
}

function trimSpace($string)
{
    return preg_replace('/\s+/', '', trim($string));
}
	
