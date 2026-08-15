<?php

class PHASTAPI_UTILS
{
    public static function PutFileByBase64( $file_path, $base64 )
    {
        $dir = dirname($file_path);
        if (!is_dir($dir))
            mkdir($dir, 0777, true);

        $data = base64_decode($base64);
        file_put_contents($file_path, $data);

        return true;
    }

    public static function NumPad( $num, $length )
    {
        return str_pad($num, $length, "0", STR_PAD_LEFT);
    }

    public static function GenUUID()
    {
        $data = random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}