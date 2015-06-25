<?php

function iterative_bin($x, $a, $b)
{
    $bt;
    
    $g_ab;
    $g_a; 
    $g_b;
    
    if (($a + $b) >= 171)
        $g_ab = iterative_land_lgamma_stirling($a+$b);
    else
        $g_ab = log(iterative_land_ios_gamma($a+$b));
    
    if ($a >= 171)
        $g_a = iterative_land_lgamma_stirling($a);
    else
        $g_a = log(iterative_land_ios_gamma($a));
    
    if ($b >= 171)
        $g_b = iterative_land_lgamma_stirling($b);
    else
        $g_b = log(iterative_land_ios_gamma($b));
    
    
    $bt = exp($g_ab - $g_a - $g_b + $a*log($x)+$b*log(1.0-$x));
    
    
    if ($x == 0)
        $bt = 0;
    
    if ($x < (($a + 1.0)/($a + $b + 2.0)))
        return $bt*iterative_bcf($a,$b,$x)/$a;
    else
        return 1 - ($bt*iterative_bcf($b,$a,1.0-$x)/$b);
}


function iterative_bcf($a, $b, $x)
{
    $maxit = 100;
    $eps = 3e-16;
    $fpmin = 1e-30;
    $aa;
    $c;
    $d;
    $del;
    $h;
    $qab; 
    $qam;
    $qap;
    
    $qab = $a + $b;
    $qap = $a + 1;
    $qam = $a - 1;
    
    $c = 1.0;
    $d = 1.0 - $qab*$x/$qap;
    
    if (abs($d)<$fpmin)
        $d = $fpmin;
    
    $d = 1.0/$d;
    
    $h = $d;
    
    $m2;
    
    for ($m = 1; $m < $maxit; $m++)
    {
        $m2 = 2*$m;
        $aa = $m*($b-$m)*$x/(($qam + $m2)*($a + $m2));
        $d = 1.0 + $aa*$d;
        
        if (abs($d)<$fpmin)
            $d = $fpmin;
        
        $c = 1.0 + $aa/$c;
        
        if (abs($c)<$fpmin)
            $c = $fpmin;
        
        $d = 1.0/$d;
        $h = $h*$d*$c;
        $aa = -($a + $m)*($qab + $m)*$x/(($a+$m2)*($qap+$m2));
        $d = 1.0 + $aa*$d;
        
        if (abs($d)<$fpmin)
            $d = $fpmin;
        
        $c = 1.0 + $aa/$c;
        
        if (abs($c)<$fpmin)
            $c = $fpmin;
        
        $d = 1.0/$d;
        $del = $d*$c;
        $h = $h*$del;
        
        if (abs($del-1.0)< $eps)
        {
            // std::cout << "Breaking out at iter " << m << std::endl;
            break;
        }
    }
    // std::cout << " h is " << h << std::endl;
    return $h;
}

function iterative_land_ios_gamma($x)
{
    $g = 7;
    
    $y;
    $t;
    $res_fr;
    
    $p = Array();
    
    $p[0] = 0.99999999999980993;
    $p[1] = 676.5203681218851;
    $p[2] = -1259.1392167224028;
    $p[3] = 771.32342877765313;
    $p[4] = -176.61502916214059;
    $p[5] = 12.507343278686905;
    $p[6] = -0.13857109526572012;
    $p[7] = 9.9843695780195716e-6;
    $p[8] = 1.5056327351493116e-7;
    
    if (abs($x - floor($x)) < 1e-16)
    {
        if ($x > 1) {
            $num = $x-1;

            $rval=1;
            for ($i = 2; $i <= $num; $i++)
                $rval = $rval * $i;
            return $rval;
        } else if ($x == 1)
            return 1;
        else
            return INF;	
    }
    else
    {
        $x -= 1;
        
        $y = $p[0];
        
        for ($i=1; $i < $g+2; $i++)
        {
            $y = $y + $p[$i]/($x + $i);
        }
        $t = $x + $g + 0.5;
        
       
        $res_fr = sqrt(2*pi()) * exp((($x+0.5)*log($t))-$t)*$y;
        
        return $res_fr;
    }
}

function iterative_land_lgamma_stirling($x)
{
    $t = 0.5*log(2*pi()) - 0.5*log($x) + $x*(log($x))-$x;
    
    $x2 = $x * $x;
    $x3 = $x2 * $x;
    $x4 = $x3 * $x;
    
    $err_term = log(1 + (1.0/(12*$x)) + (1.0/(288*$x2)) - (139.0/(51840*$x3))
                          - (571.0/(2488320*$x4)));
    
    $res = $t + $err_term;
    return $res;
}

function iterative_ib($p, $a1, $b1)
{
    $x = 0;
    $a = 0;
    $b = 1;
    $precision = 1e-15;
    
    $iter_num = 0;
    
    while ((($b - $a) > $precision) & ($iter_num < 100))
    {
        $x = ($a + $b) / 2;
        
        if (iterative_bin($x,$a1,$b1) > $p)
            $b = $x;
        else
            $a = $x;
        $iter_num = $iter_num + 1;
    }
    
    return $x;
}

function iterative_urf($a, $b) {
    return $a + rand()/getrandmax() * ($b-$a);
}
