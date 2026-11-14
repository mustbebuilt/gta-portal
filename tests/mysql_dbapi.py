"""Narrow DB-API bridge for the existing HTTP fixture's independent SQL checks.
It refuses everything except the engine runner's own private localhost schema.
"""
from pathlib import Path
import datetime,decimal,hmac,json,os,re,tempfile
import pymysql

class Row:
    def __init__(self,columns,values):self.columns=columns;self.values=values
    def keys(self):return self.columns
    def __getitem__(self,key):return self.values[key] if isinstance(key,int) else self.values[self.columns.index(key)]
    def __iter__(self):return iter(self.values)

def normalise(value):
    if isinstance(value,datetime.datetime):return value.strftime('%Y-%m-%d %H:%M:%S')
    if isinstance(value,datetime.date):return value.isoformat()
    if isinstance(value,decimal.Decimal):return str(value)
    return value

class Cursor:
    def __init__(self,cursor,owner):self.cursor=cursor;self.owner=owner
    @property
    def lastrowid(self):return self.cursor.lastrowid
    def wrap(self,row):
        if row is None:return None
        values=tuple(normalise(v) for v in row)
        return Row([c[0] for c in self.cursor.description],values) if self.owner.row_factory else values
    def fetchone(self):return self.wrap(self.cursor.fetchone())
    def fetchall(self):return [self.wrap(row) for row in self.cursor.fetchall()]
    def __iter__(self):
        while True:
            row=self.fetchone()
            if row is None:break
            yield row

class Connection:
    def __init__(self):
        self.row_factory=None
        marker_path=Path(os.environ['GTA_ENGINE_MARKER']);m=json.loads(marker_path.read_text())
        host=os.environ.get('GTA_HTTP_MYSQL_HOST');port=int(os.environ.get('GTA_HTTP_MYSQL_PORT','0'));database=os.environ.get('GTA_HTTP_MYSQL_DATABASE');user=os.environ.get('GTA_HTTP_MYSQL_USER')
        assert str(marker_path.parent).startswith(tempfile.gettempdir()+'/gta-mariadb-') and m.get('mode')=='local-disposable' and host=='127.0.0.1' and m.get('port')==port and m.get('http_database')==database and m.get('username')==user and re.fullmatch(r'gta_test_[a-f0-9]{16}',database or '') and hmac.compare_digest(m.get('token',''),os.environ.get('GTA_ENGINE_TOKEN','')),'Refusing non-isolated native HTTP fixture connection'
        self.connection=pymysql.connect(host=host,port=port,user=user,password=os.environ['GTA_HTTP_MYSQL_PASSWORD'],database=database,charset='utf8mb4',autocommit=False)
    def execute(self,query,bindings=()):
        cursor=self.connection.cursor()
        if bindings:cursor.execute(query.replace('%','%%').replace('?','%s'),bindings)
        else:cursor.execute(query)
        return Cursor(cursor,self)
    def __enter__(self):return self
    def __exit__(self,kind,value,tb):
        try:self.connection.rollback() if kind else self.connection.commit()
        finally:self.connection.close()

class Module:
    Row=Row
    OperationalError=pymysql.OperationalError
    @staticmethod
    def connect(_database):return Connection()
