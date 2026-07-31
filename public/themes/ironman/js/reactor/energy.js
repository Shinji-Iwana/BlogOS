/* ==========================================================
   energy.js
   ----------------------------------------------------------
   役割：
   ・Arc Reactor Energy Line生成
   ・ランダム配置
   ・ランダム発光
   ・ランダム速度

   対象：
   ・.energy-rotate

   構造：

   energy-lines
        |
        └── energy-rotate
                |
                └── energy-line


   ※Animation CSSはenergy.css側
   ※HUD禁止
   ※Effects禁止
   ========================================================== */


document.addEventListener(
    "DOMContentLoaded",
    () => {


        const container =
            document.querySelector(
                ".energy-rotate"
            );


        if (!container) {

            return;

        }



        /*
        ========================================
        Energy設定
        ========================================
        */


        const ENERGY_COUNT = 24;



        /*
        ========================================
        Energy生成
        ========================================
        */


        for (
            let i = 0;
            i < ENERGY_COUNT;
            i++
        ) {


            const line =
                document.createElement(
                    "div"
                );


            line.className =
                "energy-line";



            /*
            --------------------------------
            角度
            --------------------------------

            0〜360度へランダム配置

            */

            const angle =
                Math.random() * 360;



            /*
            --------------------------------
            長さ
            --------------------------------

            18〜43%

            */

            const length =
                18 +
                Math.random() * 25;



            /*
            --------------------------------
            明るさ
            --------------------------------

            0.5〜1.3

            */

            const brightness =
                0.5 +
                Math.random() * 0.8;



            /*
            --------------------------------
            透明度
            --------------------------------

            */

            const opacity =
                0.4 +
                Math.random() * 0.6;



            /*
            --------------------------------
            アニメーション速度
            --------------------------------

            */

            const duration =
                1.5 +
                Math.random() * 3;



            /*
            =================================
            CSS変数設定
            =================================
            */


            line.style.setProperty(
                "--energy-angle",
                `${angle}deg`
            );


            line.style.setProperty(
                "--energy-length",
                `${length}%`
            );


            line.style.setProperty(
                "--energy-brightness",
                brightness
            );


            line.style.setProperty(
                "--energy-opacity",
                opacity
            );


            line.style.setProperty(
                "--energy-duration",
                `${duration}s`
            );



            /*
            =================================
            DOM追加
            =================================
            */


            container.appendChild(
                line
            );


        }


    }
);
