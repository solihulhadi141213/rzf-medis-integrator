Folder PATH listing
Volume serial number is 2A85-ACB5
C:.
|   .gitignore
|   index.php
|   LICENSE
|   README-structure.txt
|   README.md
|   struktur.txt
|   
+---DB
|       default.sql
|       
+---Storage
|   +---DICOM
|   +---Doc
|   \---Img
|       +---Account
|       |       24a166d3a8eeed3b46167f93624ca83a.png
|       |       64ffa523717340c164e75f3f74302f.png
|       |       e7dff11a659df09176d5b15f282ea193.png
|       |       f0b31cf59510443af5f6b75bbc7baec2.png
|       |       
|       \---Patient
+---_API
|   +---Account
|   |       CreatAccount.php
|   |       DeleteAccount.php
|   |       DetailAccount.php
|   |       ListAccount.php
|   |       ListAccountLevel.php
|   |       ListServiceFeature.php
|   |       Login.php
|   |       Logout.php
|   |       UpdateAccount.php
|   |       UpdateAccountPassword.php
|   |       UpdateAccountPermission.php
|   |       UpdateAccountPhoto.php
|   |       
|   +---ApiKey
|   |       CreatApiKey.php
|   |       DeleteApiKey.php
|   |       ListApiKey.php
|   |       UpdateApiKey.php
|   |       
|   +---Patient
|   +---Reference
|   |   +---BodySite
|   |   |       bodysite.php
|   |   |       
|   |   +---ICD
|   |   |       icd.php
|   |   |       
|   |   \---Region
|   |           City.php
|   |           District.php
|   |           Province.php
|   |           Vilage.php
|   |           
|   +---Satusehat
|   |       CreatCredential.php
|   |       CredentialStatus.php
|   |       DeleteCredential.php
|   |       DetailCredential.php
|   |       ListCredential.php
|   |       UpdateCredential.php
|   |       
|   \---Token
|           get_token.php
|           
+---_Config
|       Connection.php
|       Helper.php
|       RateLimiter.php
|       
\---_Proxy
    +---DocumentProxy
    \---ImageProxy
            AccountImage.php
            
